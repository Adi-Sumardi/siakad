<?php

namespace App\Services\Billing;

use App\Jobs\SendQontakTemplateMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Nudges families about a bill, three times at most and never twice on the
 * same beat.
 *
 * Reminder fatigue is the failure mode here: a family that gets four messages
 * about one SPP stops reading any of them, and then misses the one that
 * mattered. So the beats are few and deliberate - a week out to plan, the day
 * before to act, once after to notice - and each beat is recorded per channel
 * under a unique index so a job that runs twice cannot send twice.
 *
 * Channels are independent: email and WhatsApp each get the same data
 * snapshot and each own their own sent-row, log row, and failure - one being
 * down never silences the other. WhatsApp is always queued rather than sent
 * inline: due dates cluster on the same day of the month, so this daily sweep
 * is exactly the burst an unthrottled gateway cannot absorb. SPP goes out
 * through Qontak's approved 'reminder_spp_school' template (an official
 * Business line can only send a template to a cold number); every other fee
 * type stays on the free-text Sendago path until it gets its own template.
 */
class BillReminderSender
{
    /** Which beat a bill is on today, or null if it is on none of them. */
    public const KINDS = ['h7', 'h1', 'overdue'];

    public function __construct(
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
        private BillingApiGateway $billingApi,
    ) {}

    public function kindFor(Bill $bill): ?string
    {
        if (! $bill->isOpen()) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($bill->due_date->startOfDay(), false);

        return match (true) {
            $days === 7 => 'h7',
            $days === 1 => 'h1',
            $days === -3 => 'overdue',
            default => null,
        };
    }

    /**
     * Sends one reminder beat through every channel the billing contact
     * actually has, or returns false when nothing could be sent at all.
     */
    public function send(Bill $bill, string $kind): bool
    {
        // A payment can land between the scheduler's SELECT and this send -
        // the database decides, never the in-memory snapshot.
        $bill->refresh();

        if (! $bill->isOpen()) {
            Log::info('[Reminder] Bill dilewati - sudah lunas/ditutup', [
                'bill' => $bill->bill_number,
                'status' => $bill->status,
                'kind' => $kind,
            ]);

            return false;
        }

        $guardian = $this->billingContactFor($bill);

        if (! $guardian) {
            Log::warning('[Reminder] Bill has no billing contact', ['bill' => $bill->bill_number]);

            return false;
        }

        $data = $this->buildData($bill, $guardian, $kind);
        $anySent = false;

        foreach ($this->channelsFor($guardian) as $channel => $to) {
            if (BillReminder::where('bill_id', $bill->id)->where('kind', $kind)->where('channel', $channel)->exists()) {
                Log::info('[Reminder] Dilewati - beat ini sudah pernah dikirim di channel ini', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                ]);

                continue;
            }

            try {
                // Claimed before sending, not after: a lost race on the unique
                // index then means "someone else is sending this", never "it
                // was already sent twice".
                BillReminder::create([
                    'bill_id' => $bill->id,
                    'kind' => $kind,
                    'channel' => $channel,
                    'sent_to' => $to,
                    'sent_at' => now(),
                ]);

                $result = $channel === 'email'
                    ? $this->sendEmail($bill, $to, $data)
                    : $this->queueWhatsApp($bill, $guardian, $to, $data);
            } catch (UniqueConstraintViolationException $e) {
                Log::info('[Reminder] Dilewati - channel ini sudah tercatat terkirim (race)', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                ]);

                continue;
            } catch (\Throwable $e) {
                Log::warning('[Reminder] Gagal menyiapkan pengiriman', [
                    'bill' => $bill->bill_number,
                    'kind' => $kind,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $result->success
                ? Log::info('[Reminder] Terkirim/diantrikan', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel])
                : Log::warning('[Reminder] Gagal terkirim', ['bill' => $bill->bill_number, 'kind' => $kind, 'channel' => $channel, 'error' => $result->message]);

            $anySent = $anySent || $result->success;
        }

        return $anySent;
    }

    /**
     * The retry sweep's second chance for a reminder whose delivery failed -
     * sent inline here, since the sweep is already bounded and paced. Amounts
     * are re-derived fresh, and only while the bill is still open.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $bill = $log->notifiable;
        $kind = $log->payload['kind'] ?? null;

        if (! $bill instanceof Bill || ! in_array($kind, self::KINDS, true)) {
            return NotificationResult::fail('Tagihan atau jenis pengingat sudah tidak ada.');
        }

        $bill->refresh();

        if (! $bill->isOpen()) {
            return NotificationResult::fail('Tagihan sudah tidak terbuka (lunas/dibatalkan).');
        }

        $guardian = $this->billingContactFor($bill);

        if (! $guardian) {
            return NotificationResult::fail('Tagihan tanpa kontak penagihan.');
        }

        $data = $this->buildData($bill, $guardian, $kind);

        return $log->channel === 'email'
            ? $this->mail->send($log->recipient, 'bill_reminder', $data)
            : $this->whatsapp->sendMessage($log->recipient, $this->whatsappMessage($data));
    }

    private function billingContactFor(Bill $bill): ?Guardian
    {
        return $bill->student->billingContact();
    }

    /**
     * Every channel the contact can actually be reached on.
     *
     * @return array<string, string>
     */
    private function channelsFor(Guardian $guardian): array
    {
        $channels = [];

        if (filled($guardian->email)) {
            $channels['email'] = $guardian->email;
        }

        if (filled($guardian->no_hp)) {
            $channels['whatsapp'] = (string) $guardian->no_hp;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    private function buildData(Bill $bill, Guardian $guardian, string $kind): array
    {
        return [
            'guardian_name' => $guardian->nama,
            'student_name' => $bill->student->nama_panggilan ?: $bill->student->nama_lengkap,
            'description' => $bill->description,
            'amount' => number_format((float) $bill->remaining_amount, 0, ',', '.'),
            'due_date' => $bill->due_date->translatedFormat('d F Y'),
            'kind' => $kind,
        ];
    }

    private function sendEmail(Bill $bill, string $to, array $data): NotificationResult
    {
        $result = $this->mail->send($to, 'bill_reminder', $data);

        // Recorded whether or not the gateway accepted it - the retry sweep
        // picks up a failed row; an unrecorded one would be sent twice.
        NotificationLog::create([
            'channel' => 'email',
            'template' => 'bill_reminder',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        return $result;
    }

    private function queueWhatsApp(Bill $bill, Guardian $guardian, string $phone, array $data): NotificationResult
    {
        if ($bill->feeType?->code === 'spp' && filled(config('services.qontak.spp_reminder_template_id'))) {
            return $this->queueSppReminderTemplate($bill, $guardian, $phone);
        }

        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'bill_reminder',
            'recipient' => $phone,
            'payload' => $data,
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        SendWhatsAppMessage::dispatch($phone, $this->whatsappMessage($data), $log->ulid);

        return NotificationResult::ok(['mode' => 'queued']);
    }

    private function whatsappMessage(array $data): string
    {
        $lead = match ($data['kind']) {
            'h7' => "Pengingat: {$data['description']} untuk {$data['student_name']} jatuh tempo "
                ."{$data['due_date']} (7 hari lagi).",
            'h1' => "Besok jatuh tempo: {$data['description']} untuk {$data['student_name']}.",
            default => "{$data['description']} untuk {$data['student_name']} sudah lewat jatuh tempo "
                ."({$data['due_date']}).",
        };

        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            .$lead."\n\n"
            ."Sisa tagihan: Rp {$data['amount']}\n\n"
            .'Pembayaran bisa dilakukan lewat aplikasi sekolah. Abaikan pesan ini bila sudah dibayar.';
    }

    /**
     * Registers both banks' VA for this bill (idempotent - see
     * BillingApiGateway::ensureReminderVaPair()) and queues the approved
     * template with both numbers. Body variables, in order: nama anak, bulan
     * tagihan, jumlah, VA Muamalat, kode bayar BSI (the VA minus its
     * 4-digit institution code - 7895 for SPP).
     */
    private function queueSppReminderTemplate(Bill $bill, Guardian $guardian, string $phone): NotificationResult
    {
        try {
            $va = $this->billingApi->ensureReminderVaPair($bill, $guardian);
        } catch (\Throwable $e) {
            Log::warning('[BillReminderSender] Failed to register VA pair for SPP reminder', [
                'bill' => $bill->bill_number,
                'error' => $e->getMessage(),
            ]);

            return NotificationResult::fail('Gagal mendaftarkan Virtual Account: '.$e->getMessage());
        }

        $muamalatVa = $va['muamalat']['va_number'] ?? '';
        $bsiVa = $va['bsi']['va_number'] ?? '';
        $bsiPaymentCode = mb_strlen($bsiVa) > 4 ? mb_substr($bsiVa, 4) : $bsiVa;
        $period = $bill->issued_at?->translatedFormat('F Y') ?? $bill->due_date->translatedFormat('F Y');
        $amount = number_format((float) $bill->remaining_amount, 0, ',', '.');

        // Not updated to 'sent'/'failed' by the job (SendQontakTemplateMessage
        // takes no log ulid) - the job's own retries and log line carry the
        // outcome; this row keeps the per-bill send history consistent.
        NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => $phone,
            'payload' => [
                'student_name' => $bill->student->nama_lengkap,
                'period' => $period,
                'amount' => $amount,
                'va_muamalat' => $muamalatVa,
                'va_bsi_payment_code' => $bsiPaymentCode,
            ],
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        SendQontakTemplateMessage::dispatch(
            phone: '62'.substr($phone, 1),
            toName: $guardian->nama ?: 'Orang Tua/Wali',
            templateId: config('services.qontak.spp_reminder_template_id'),
            bodyValues: [$bill->student->nama_lengkap, $period, $amount, $muamalatVa, $bsiPaymentCode],
        );

        return NotificationResult::ok(['mode' => 'queued']);
    }
}
