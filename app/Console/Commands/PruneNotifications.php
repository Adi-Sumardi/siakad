<?php

namespace App\Console\Commands;

use App\Models\AccountInvitation;
use App\Models\LoginOtp;
use App\Models\NotificationLog;
use Illuminate\Console\Command;

/**
 * Retention for the notification family of tables (audit r2 2026-10-05).
 *
 * notification_logs grows a row per OTP request (both channels), per
 * reminder beat per channel, per receipt - nothing ever deleted it, and a
 * school-year of traffic is real weight for zero operational value. The
 * split: SENT rows are history nobody reads after a while and go first;
 * FAILED rows are the monitoring/audit trail (the refund and
 * reconciliation questions land there) and are kept longer, but not
 * forever. login_otps keep one week past expiry for the abuse questions,
 * expired invitations 90 days.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--sent-days=180 : Hapus baris terkirim lebih tua dari ini} {--failed-days=365 : Hapus baris gagal lebih tua dari ini}';

    protected $description = 'Pangkaskan notification_logs, login_otps, dan undangan kedaluwarsa';

    public function handle(): int
    {
        $sentDays = max(30, (int) $this->option('sent-days'));
        $failedDays = max($sentDays, (int) $this->option('failed-days'));

        $sent = NotificationLog::query()
            ->where('status', 'sent')
            ->where('created_at', '<', now()->subDays($sentDays))
            ->delete();

        $failed = NotificationLog::query()
            ->whereIn('status', ['failed', 'queued'])
            ->where('created_at', '<', now()->subDays($failedDays))
            ->delete();

        // One week past expiry is plenty for "was this number being
        // hammered" questions; the codes themselves are long dead by then.
        $otps = LoginOtp::query()
            ->where('expires_at', '<', now()->subDays(7))
            ->delete();

        $invitations = AccountInvitation::query()
            ->where('expires_at', '<', now()->subDays(90))
            ->delete();

        $this->info(sprintf(
            'Terhapus: %d notifikasi terkirim (> %d hari), %d gagal/antre (> %d hari), %d OTP kedaluwarsa, %d undangan kedaluwarsa.',
            $sent, $sentDays, $failed, $failedDays, $otps, $invitations,
        ));

        return self::SUCCESS;
    }
}
