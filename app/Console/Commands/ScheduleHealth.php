<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Is the scheduler actually running? (audit 2026-10-05)
 *
 * The scheduler container had no healthcheck and no last-run record
 * anywhere: dead at 00:30 on the 1st meant that month's SPP silently never
 * issued; dead at 07:00 meant the day's H-7/H-1 reminder beats were lost
 * forever (day-specific, no catch-up firing). The heartbeat schedule writes
 * one cache row per minute; this command reads it and exits non-zero when
 * it has gone stale, so uptime monitoring (docker healthcheck, cron alert,
 * a supervisor) has something concrete to watch. Catch-up is safe by
 * design - every scheduled command is idempotent - see docs/07-OPERASIONAL
 * .md for the runbook.
 */
class ScheduleHealth extends Command
{
    protected $signature = 'schedule:health {--stale-minutes=5 : Ambang detak dianggap basi}';

    protected $description = 'Periksa detak scheduler (untuk healthcheck/monitoring eksternal)';

    public function handle(): int
    {
        $staleMinutes = max(2, (int) $this->option('stale-minutes'));

        $heartbeat = Cache::get('scheduler:heartbeat');

        if (! $heartbeat) {
            $this->error('Tidak ada detak scheduler sama sekali - scheduler belum pernah berjalan pada cache store ini.');

            return self::FAILURE;
        }

        $age = now()->diffInMinutes($heartbeat);

        if ($age > $staleMinutes) {
            $this->error("Detak scheduler basi: terakhir {$age} menit lalu (ambang {$staleMinutes}). Scheduler kemungkinan mati - lihat docs/07-OPERASIONAL.md untuk runbook catch-up.");

            return self::FAILURE;
        }

        $this->info("Scheduler sehat - detak terakhir {$age} menit lalu.");

        return self::SUCCESS;
    }
}
