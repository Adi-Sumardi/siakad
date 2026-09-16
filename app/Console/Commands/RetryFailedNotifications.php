<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationRetryService;
use Illuminate\Console\Command;

/**
 * The failed-notification sweep (audit C2 / P2-4). Bounded: three attempts
 * total per delivery, a 24-hour lookback - an attendance notice from two
 * days ago is noise, not a retry - and half-hour beats from the schedule.
 *
 * login_otp never appears here: a code that expires in minutes is worthless
 * on a retry, and `otp:issue` is the documented manual recovery when both
 * gateways are properly down.
 */
class RetryFailedNotifications extends Command
{
    protected $signature = 'notifications:retry-failed
                            {--dry-run : Tampilkan apa yang akan dicoba ulang, tanpa mengirim}';

    protected $description = 'Coba ulang notifikasi email/WhatsApp yang gagal terkirim (maks 3x, jendela 24 jam)';

    public function handle(NotificationRetryService $service): int
    {
        if ($this->option('dry-run')) {
            $due = $service->due();

            foreach ($due as $log) {
                $this->line("  {$log->template} → {$log->recipient} (percobaan ke-".($log->attempts + 1).')');
            }

            $this->info($due->count().' akan dicoba ulang. Tidak ada yang dikirim (dry run).');

            return self::SUCCESS;
        }

        $stats = $service->retryDue();

        $this->info(sprintf(
            '%d dicoba ulang: %d terkirim, %d masih gagal, %d menyerah (perlu tindak lanjut manual).',
            $stats['retried'],
            $stats['sent'],
            $stats['still_failed'],
            $stats['gave_up'],
        ));

        return self::SUCCESS;
    }
}
