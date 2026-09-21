<?php

namespace App\Console\Commands;

use App\Models\DailyAttendanceSetting;
use App\Models\DailySession;
use App\Models\Holiday;
use App\Models\Term;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily attendance heartbeat (DESAIN-PRESENSI-HARIAN.md §5B). Every run
 * does two passes:
 *
 *   open  - for each unit whose settings say today is an attendance day, make
 *           sure today's masuk/pulang sessions exist. firstOrCreate against
 *           the (unit, date, type) unique makes this idempotent (R5): a
 *           twice-fired schedule creates nothing the second time.
 *   close - every session whose window has ended gets closed, and a masuk
 *           window sweeps its still-unmarked students into 'alpa' - the
 *           guarantee that a forgotten marking surfaces as an alpa to
 *           correct, never a data hole.
 *
 * Runs every few minutes rather than at fixed hours so a settings edit made
 * mid-morning is picked up the same day without anyone restarting anything.
 */
class SweepDailyAttendance extends Command
{
    protected $signature = 'attendance:daily-sweep
                            {--dry-run : Tampilkan yang akan dibuka/ditutup, tanpa menulis}';

    protected $description = 'Buka sesi presensi harian sesuai setting unit & tutup yang lewat jendela waktunya';

    public function handle(DailyAttendanceService $service): int
    {
        $now = Carbon::now('Asia/Jakarta');

        // The dry run must come FIRST and write nothing: ensureSessionsForDate
        // creates rows (firstOrCreate), so the old order - open pass, then
        // check the flag - left sessions behind on a --dry-run, the exact
        // opposite of the option's own description.
        if ($this->option('dry-run')) {
            $willOpen = 0;
            $holiday = Holiday::query()->whereDate('date', $now->toDateString())->exists();
            $hasTerm = Term::current() !== null;

            foreach (DailyAttendanceSetting::query()->where('enabled', true)->get() as $setting) {
                if (! $hasTerm || $holiday || ! $setting->runsOn($now->dayOfWeekIso)) {
                    continue;
                }

                foreach ($setting->pulang_enabled ? ['masuk', 'pulang'] : ['masuk'] as $type) {
                    $exists = DailySession::query()
                        ->where('school_unit_id', $setting->school_unit_id)
                        ->whereDate('date', $now->toDateString())
                        ->where('type', $type)
                        ->exists();

                    if (! $exists) {
                        $willOpen++;
                    }
                }
            }

            $due = DailySession::query()
                ->where('status', 'open')
                ->where('closes_at', '<=', $now)
                ->count();

            $this->info("Dry run: {$willOpen} sesi akan dibuka, {$due} akan ditutup.");

            return self::SUCCESS;
        }

        $opened = 0;

        foreach (DailyAttendanceSetting::query()
            ->where('enabled', true)
            ->with('schoolUnit')
            ->get() as $setting) {
            if ($service->ensureSessionsForDate($setting, $now)->contains(fn ($s) => $s->wasRecentlyCreated)) {
                $opened++;
            }
        }

        $due = DailySession::query()
            ->where('status', 'open')
            ->where('closes_at', '<=', $now)
            ->get();

        $swept = 0;

        foreach ($due as $session) {
            $swept += $service->closeAndSweep($session);
        }

        $this->info("{$opened} sesi dibuka, ".count($due)." ditutup, {$swept} siswa disapu ke alpa.");

        return self::SUCCESS;
    }
}
