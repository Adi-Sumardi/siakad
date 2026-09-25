<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends one WhatsApp message through Sendago, throttled.
 *
 * Sendago is not the official WhatsApp Business API - it's an unofficial
 * gateway (whatsapp-web.js) behind a connected number impersonating a real
 * phone. Many messages to many distinct numbers landing in the same second
 * (e.g. many guardians requesting an OTP at once, or a batch of SPP
 * reminders) is exactly the pattern WhatsApp's anti-spam detection flags,
 * and there's no SLA protecting the number if it does. Queued and
 * rate-limited (see the 'whatsapp-messages' limiter in AppServiceProvider)
 * so a burst becomes a steady drip instead, without the request that
 * triggered it waiting on or failing because of it. Same fix, same reasoning
 * as PMB's App\Jobs\SendWhatsAppMessage - PMB found the underlying gateway
 * had never been rate-limited at all.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    // $tries=0 + retryUntil (audit T42-a): a rate-limit release counts as an
    // attempt, so the old tries=3 meant a burst bigger than ~3x the
    // per-minute limit killed its tail with MaxAttemptsExceeded - handle()
    // never ran, the NotificationLog row stayed 'queued' forever, and no
    // screen ever showed why. With retryUntil the job simply waits out the
    // limiter; genuine send failures still back off and die at the horizon.
    public int $tries = 0;

    public array $backoff = [10, 30, 60, 120];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function __construct(
        public string $phone,
        public string $message,
        /**
         * When the caller already created a NotificationLog row for this
         * send (see OtpService::queueWhatsApp()), its status/error/sent_at
         * are updated here once the send is actually attempted - queuing
         * means the row can no longer be written synchronously at the point
         * of sending.
         */
        public ?string $notificationLogUlid = null,
        /**
         * Set for messages that carry a credential (an activation URL -
         * audit T51-a): the plaintext then lives only in QueueSecret's
         * encrypted, expiring store and `message` is an empty placeholder,
         * so nothing sensitive serializes into jobs.payload.
         */
        public ?string $secretKey = null,
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('whatsapp-messages')];
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        // Atomic claim (audit T64-b): the read-then-send guard let a sweep
        // re-queue racing this job's backoff both pass as 'not sent yet'
        // and physically deliver twice.
        if (! NotificationLog::claimDelivery($this->notificationLogUlid)) {
            return;
        }

        $message = $this->message;

        if ($this->secretKey !== null) {
            // Credential-bearing message (audit T51-a): the body comes from
            // the encrypted store; if its window closed while this job
            // waited, the invitation it announces is expired anyway.
            $message = \App\Services\Security\QueueSecret::take($this->secretKey) ?? '';

            if ($message === '') {
                if ($this->notificationLogUlid) {
                    NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                        'status' => 'failed',
                        'error' => 'Pesan undangan kedaluwarsa sebelum sempat terkirim dari antrean.',
                        'claimed_at' => null,
                    ]);
                }

                return;
            }
        }

        $result = $gateway->sendMessage($this->phone, $message);

        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => $result->success ? 'sent' : 'failed',
                'error' => self::successError($result),
                'sent_at' => $result->success ? now() : null,
                // The outcome releases the delivery claim (audit T64-b).
                'claimed_at' => null,
                // The row's attempts already counts the send that created
                // it (column default 1) - only the job's own retries add to
                // it (audit T44).
                ...(($this->attempts ?? 1) > 1 ? ['attempts' => NotificationLog::rawAttemptIncrement()] : []),
            ]);
        }

        if (! $result->success) {
            Log::warning('[SendWhatsAppMessage] Send failed', [
                'phone' => $this->phone,
                'error' => $result->message,
            ]);

            throw new RuntimeException($result->message ?? 'Sendago gagal mengirim pesan.');
        }
    }

    /**
     * The safety net for deaths that never reach handle() (audit T42-a):
     * without this, a MaxAttemptsExceeded job left its NotificationLog row
     * 'queued' forever - invisible to the retry sweep (failed-only) and to
     * the failure dashboard alike.
     */
    public function failed(?\Throwable $e): void
    {
        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => 'failed',
                'error' => $e?->getMessage() ?? 'Pengiriman gagal setelah seluruh percobaan.',
                'sent_at' => null,
                'claimed_at' => null,
            ]);
        }
    }

    /**
     * A successful result still carries a warning when the gateway ran in
     * its log-only mode (blank credentials - audit T64-a): the row reads
     * 'sent' either way, but the error column must say the message never
     * physically left the building, or a misconfigured production box
     * looks fully green for weeks.
     */
    private static function successError(\App\Services\Notification\NotificationResult $result): ?string
    {
        if (($result->raw['mode'] ?? null) === 'log-only') {
            return 'Mode log-only: kredensial gateway kosong - pesan TIDAK benar-benar terkirim.';
        }

        return null;
    }
}
