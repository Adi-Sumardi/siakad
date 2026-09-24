<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notification\QontakWhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a login OTP over WhatsApp via Mekari Qontak's approved 'otp_login'
 * Authentication template (ported from PMB 2026-09-22, same Qontak
 * account/WABA - see App\Services\Notification\QontakWhatsAppGateway's
 * docblock for the full contract).
 *
 * Separate from the generic App\Jobs\SendWhatsAppMessage (still Sendago,
 * still used for every other WhatsApp send this app makes) because an
 * official WhatsApp Business line can only ever send an approved template
 * to a number that hasn't messaged first - there is no "send free text,
 * template as a fallback" here, the template send IS the only attempt.
 * $notificationLogUlid keeps the same NotificationLog row this app already
 * tracks every WhatsApp send through (see OtpService::queueWhatsApp()).
 */
class SendOtpWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    // $tries=0 + retryUntil (audit T42-a): a rate-limit release counts as an
    // attempt, so the old tries=3 let a reminder burst starve login OTPs to
    // death - handle() never ran and nobody could sign in. The horizon is
    // the OTP's own TTL plus margin: delivering a 10-minute code after 15
    // minutes is guaranteed-useless noise.
    public int $tries = 0;

    public array $backoff = [10, 30, 60];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function __construct(
        public string $phone,
        public string $code,
        public ?string $notificationLogUlid = null,
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('whatsapp-messages')];
    }

    public function handle(QontakWhatsAppGateway $gateway): void
    {
        // See SendWhatsAppMessage::handle() (audit T44): never re-send a row
        // another lane already delivered.
        if ($this->notificationLogUlid
            && NotificationLog::where('ulid', $this->notificationLogUlid)->value('status') === 'sent') {
            return;
        }

        $result = $gateway->sendOtp($this->phone, $this->code);

        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => $result->success ? 'sent' : 'failed',
                'error' => $result->message,
                'sent_at' => $result->success ? now() : null,
                // See SendWhatsAppMessage::handle(): retries only - the row's
                // default-1 already counts the creating send (audit T44).
                ...(($this->attempts ?? 1) > 1 ? ['attempts' => NotificationLog::rawAttemptIncrement()] : []),
            ]);
        }

        if (! $result->success) {
            Log::warning('[SendOtpWhatsAppMessage] Send failed', [
                'phone' => $this->phone,
                'error' => $result->message,
            ]);

            throw new RuntimeException($result->message ?? 'Gagal mengirim kode OTP.');
        }
    }

    /** See SendWhatsAppMessage::failed() - the 'queued'-forever row is the failure nobody sees. */
    public function failed(?\Throwable $e): void
    {
        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => 'failed',
                'error' => $e?->getMessage() ?? 'Pengiriman OTP gagal setelah seluruh percobaan.',
                'sent_at' => null,
            ]);
        }
    }
}
