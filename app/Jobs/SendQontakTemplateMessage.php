<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notification\QontakWhatsAppGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends any approved Qontak WhatsApp template - the generic counterpart to
 * SendOtpWhatsAppMessage (which only ever sends the 'otp_login' one). Every
 * non-OTP WhatsApp notice that needs to reach a cold number (one that
 * hasn't messaged the business first) goes through here instead of the
 * free-text Sendago path, for the same reason OTP does - see
 * QontakWhatsAppGateway's own docblock. Ported from PMB 2026-09-22, where
 * this same job first shipped for the selection-test schedule notice.
 */
class SendQontakTemplateMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // $tries=0 + retryUntil (audit T42-a), same reason as the other WhatsApp
    // jobs: rate-limit releases must not burn the job's attempts.
    public int $tries = 0;

    public array $backoff = [10, 30, 60, 120];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @param  string[]  $bodyValues
     * @param  string[]  $buttonValues
     */
    public function __construct(
        public string $phone,
        public string $toName,
        public string $templateId,
        public array $bodyValues,
        public array $buttonValues = [],
        /**
         * The NotificationLog row the caller created for this send (audit
         * T43): without it the row stayed 'queued' forever on every outcome
         * - success included - invisible to the retry sweep, the manual
         * resend lane and the failure dashboard alike.
         */
        public ?string $notificationLogUlid = null,
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('whatsapp-messages')];
    }

    public function handle(QontakWhatsAppGateway $gateway): void
    {
        // Atomic claim (audit T64-b) - see SendWhatsAppMessage::handle().
        if (! NotificationLog::claimDelivery($this->notificationLogUlid)) {
            return;
        }

        $result = $gateway->sendTemplate($this->phone, $this->toName, $this->templateId, $this->bodyValues, $this->buttonValues);

        if ($this->notificationLogUlid) {
            NotificationLog::where('ulid', $this->notificationLogUlid)->update([
                'status' => $result->success ? 'sent' : 'failed',
                'error' => $result->success
                    ? ((($result->raw['mode'] ?? null) === 'log-only')
                        ? 'Mode log-only: kredensial gateway kosong - pesan TIDAK benar-benar terkirim.'
                        : null)
                    : $result->message,
                'sent_at' => $result->success ? now() : null,
                // The outcome releases the delivery claim (audit T64-b).
                'claimed_at' => null,
                // See SendWhatsAppMessage::handle(): retries only (audit T44).
                ...(($this->attempts ?? 1) > 1 ? ['attempts' => NotificationLog::rawAttemptIncrement()] : []),
            ]);
        }

        if (! $result->success) {
            Log::warning('[SendQontakTemplateMessage] Send failed', [
                'phone' => $this->phone,
                'template_id' => $this->templateId,
                'error' => $result->message,
            ]);

            throw new RuntimeException($result->message ?? 'Gagal mengirim pesan template WhatsApp.');
        }
    }

    /** See SendWhatsAppMessage::failed() - the 'queued'-forever row is the failure nobody sees. */
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
}
