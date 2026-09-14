<?php

namespace App\Console\Commands;

use App\Models\DailySession;
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
 *           window sweeps its still-unmarked students into 'alpa' with the
 *           WhatsApp alert - the guarantee that a forgotten marking leaves a
 *           notification, not a data hole.
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

        $opened = 0;

        foreach (\App\Models\DailyAttendanceSetting::query()
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

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$opened} sesi akan dibuka, ".count($due).' akan ditutup.');

            return self::SUCCESS;
        }

        $swept = 0;

        foreach ($due as $session) {
            $swept += $service->closeAndSweep($session);
        }

        $this->info("{$opened} sesi dibuka, ".count($due)." ditutup, {$swept} siswa disapu ke alpa.");

        return self::SUCCESS;
    }
}
