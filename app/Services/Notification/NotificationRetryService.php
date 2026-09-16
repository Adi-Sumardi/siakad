<?php

namespace App\Services\Notification;

use App\Models\NotificationLog;
use App\Services\Attendance\DailyAttendanceNotifier;
use App\Services\Billing\BillReminderSender;
use App\Services\Billing\PaymentReceiptNotifier;
use App\Services\Handoff\AccountInvitationSender;
use App\Services\Points\PointThresholdNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The consumer half of notification_logs. Rows land there with status=failed
 * the moment a Sendago send is refused, and until this sweep existed nothing
 * ever read them back - a failure simply meant the OTP or SPP reminder never
 * existed (audit C2 / P2-4).
 *
 * Bounded on purpose: three attempts total, a twenty-four hour window, and
 * half-hour beats from the schedule. login_otp is excluded - a code that
 * expires in minutes is worthless on a retry, the user just asks again, and
 * `otp:issue` remains the documented manual recovery when both gateways are
 * properly down. Everything else is re-rendered fresh by its original sender
 * (the stored payload is deliberately incomplete for exactly the templates
 * that carry credentials), sent to the recipient recorded on the failed row,
 * and updates that same row - one delivery, one row of history, however
 * many attempts it takes.
 *
 * retryOne()/manualRefusal() are the human lane the ruang kontrol
 * (/admin/monitoring) uses: a manual resend bypasses the attempt cap and the
 * 24-hour window because a person decided it should go out, but never the
 * exclusions - there is no human decision that makes an expired OTP worth
 * resending.
 */
class NotificationRetryService
{
    public const MAX_ATTEMPTS = 3;

    public const WINDOW_HOURS = 24;

    /** Templates the sweep must never touch, each with the reason it is here. */
    private const EXCLUDED_TEMPLATES = [
        'login_otp' => 'kode kedaluwarsa dalam hitungan menit; user cukup minta ulang',
    ];

    public function __construct(
        private AccountInvitationSender $invitations,
        private BillReminderSender $billReminders,
        private PaymentReceiptNotifier $paymentReceipts,
        private DailyAttendanceNotifier $attendance,
        private PointThresholdNotifier $pointThresholds,
    ) {}

    /** @return Collection<int, NotificationLog> */
    public function due(): Collection
    {
        return NotificationLog::query()
            ->where('status', 'failed')
            ->whereNotIn('template', array_keys(self::EXCLUDED_TEMPLATES))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderBy('id') // oldest first: the row that waited longest gets the next slot
            ->get();
    }

    /**
     * Runs one sweep. A row whose sender throws is treated as a failed
     * attempt, not a reason to starve the rest of the queue; a row whose
     * template has no mapping is skipped with a warning (a new sender joins
     * by adding one entry to resendFor(), not by editing the sweep).
     *
     * @return array{retried: int, sent: int, still_failed: int, gave_up: int}
     */
    public function retryDue(): array
    {
        $stats = ['retried' => 0, 'sent' => 0, 'still_failed' => 0, 'gave_up' => 0];
        $exhausted = [];

        foreach ($this->due() as $log) {
            $resend = $this->resendFor($log->template);

            if (! $resend) {
                Log::warning('[NotificationRetry] Tidak ada pemetaan resend untuk template, dilewati', [
                    'template' => $log->template,
                    'ulid' => $log->ulid,
                ]);

                continue;
            }

            try {
                $result = $resend($log);
            } catch (\Throwable $e) {
                $result = NotificationResult::fail($e->getMessage());
            }

            $attempts = $this->applyResult($log, $result);

            $stats['retried']++;
            $result->success ? $stats['sent']++ : $stats['still_failed']++;

            if (! $result->success && $attempts >= self::MAX_ATTEMPTS) {
                $stats['gave_up']++;
                $exhausted[] = [
                    'ulid' => $log->ulid,
                    'template' => $log->template,
                    'recipient' => $log->recipient,
                    'error' => $result->message,
                ];
            }
        }

        // The alerting floor: rows the sweep will never pick up again are a
        // human's problem from here on, so they leave one loud line behind.
        if ($exhausted !== []) {
            Log::error(sprintf(
                '[NotificationRetry] %d notifikasi menyerah setelah %d percobaan - perlu tindak lanjut manual',
                count($exhausted),
                self::MAX_ATTEMPTS,
            ), ['rows' => $exhausted]);
        }

        return $stats;
    }

    /**
     * The manual lane: one human-decided resend, cap and window be damned.
     * The row is updated exactly like a sweep attempt, and the outcome is
     * returned as-is - "still failing" is a valid, reportable result here,
     * not something to hide behind an exception.
     */
    public function retryOne(NotificationLog $log): NotificationResult
    {
        $resend = $this->resendFor($log->template);

        if (! $resend) {
            return NotificationResult::fail("Template {$log->template} tidak punya pemetaan resend.");
        }

        try {
            $result = $resend($log);
        } catch (\Throwable $e) {
            $result = NotificationResult::fail($e->getMessage());
        }

        $this->applyResult($log, $result);

        return $result;
    }

    /**
     * Why this row may NOT be manually resent, or null when it may. The
     * controller turns a non-null reason into a 422, so the template map
     * and the exclusion list live only here.
     */
    public function manualRefusal(NotificationLog $log): ?string
    {
        return match (true) {
            $log->status === 'sent' => 'Notifikasi ini sudah terkirim — tidak perlu dikirim ulang.',
            $log->status !== 'failed' => 'Hanya notifikasi berstatus gagal yang bisa dikirim ulang.',
            isset(self::EXCLUDED_TEMPLATES[$log->template]) => self::EXCLUDED_TEMPLATES[$log->template],
            ! $this->resendFor($log->template) => "Template {$log->template} tidak punya pemetaan resend.",
            default => null,
        };
    }

    /**
     * One delivery, one row: applies a resend outcome to the row it retried.
     * Returns the new attempt count (computed before the update - the model
     * attribute changes as a side effect, so reading it back afterwards
     * would already be the new value).
     */
    private function applyResult(NotificationLog $log, NotificationResult $result): int
    {
        $attempts = $log->attempts + 1;

        $log->update([
            'attempts' => $attempts,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->success ? null : $result->message,
            'sent_at' => $result->success ? now() : $log->sent_at,
        ]);

        return $attempts;
    }

    /**
     * The template → sender map. Each sender owns the knowledge of how to
     * rebuild its own message; the sweep only routes.
     *
     * @return null|callable(NotificationLog): NotificationResult
     */
    private function resendFor(string $template): ?callable
    {
        return match ($template) {
            'school_account_invite' => fn (NotificationLog $log) => $this->invitations->resend($log),
            'bill_reminder' => fn (NotificationLog $log) => $this->billReminders->resend($log),
            'payment_receipt' => fn (NotificationLog $log) => $this->paymentReceipts->resend($log),
            'daily_masuk', 'daily_pulang', 'daily_absent' => fn (NotificationLog $log) => $this->attendance->resend($log),
            'point_threshold' => fn (NotificationLog $log) => $this->pointThresholds->resend($log),
            default => null,
        };
    }
}
