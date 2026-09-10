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

    public int $tries = 3;

    public array $backoff = [10, 30];

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
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('whatsapp-messages')];
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        $result = $gateway->sendMessage($this->phone, $this->message);

        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => $result->success ? 'sent' : 'failed',
                'error' => $result->message,
                'sent_at' => $result->success ? now() : null,
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
}
