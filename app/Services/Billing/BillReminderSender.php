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
use App\Services\Notification\PhoneNumberFormatter;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Support\Facades\Log;

/**
 * Nudges families about a bill, three times at most and never twice on the
 * same beat.
 *
 * Reminder fatigue is the failure mode here: a family that gets four messages
 * about one SPP stops reading any of them, and then misses the one that
 * mattered. So the beats are few and deliberate - a week out to plan, the day
 * before to act, once after to notice - and each is recorded under a unique
 * index so a job that runs twice cannot send twice.
 */
class BillReminderSender
{
    /** Which beat a bill is on today, or null if it is on none of them. */
    public const KINDS = ['h7', 'h1', 'overdue'];

    public function __construct(
        private MailGateway $mail,
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
     * Sends one reminder, or returns false if it was already sent or there is
     * nobody to send it to.
     */
    public function send(Bill $bill, string $kind): bool
    {
        if (BillReminder::where('bill_id', $bill->id)->where('kind', $kind)->exists()) {
            return false;
        }

        $guardian = $this->billingContactFor($bill);

        if (! $guardian) {
            // Not an error worth failing the run over - a student whose billing
            // contact was never set is a data problem for an admin, and the
            // other families still need their reminders.
            Log::warning('[Reminder] Bill has no billing contact', ['bill' => $bill->bill_number]);

            return false;
        }

        $channel = $guardian->email ? 'email' : 'whatsapp';
        $to = $guardian->email ?: (string) $guardian->no_hp;

        if (! $to) {
            return false;
        }

        $data = [
            'guardian_name' => $guardian->nama,
            'student_name' => $bill->student->nama_panggilan ?: $bill->student->nama_lengkap,
            'description' => $bill->description,
            'amount' => number_format((float) $bill->remaining_amount, 0, ',', '.'),
            'due_date' => $bill->due_date->translatedFormat('d F Y'),
            'kind' => $kind,
        ];

        // WhatsApp is queued rather than sent inline and logs itself - due
        // dates cluster on the same day of the month for most families, so
        // this daily sweep is exactly the kind of burst an unthrottled send
        // cannot absorb all at once.
        if ($channel === 'email') {
            $result = $this->mail->send($to, 'bill_reminder', $data);
            $this->log($bill, $channel, $to, $data, $result);
        } elseif ($bill->feeType?->code === 'spp') {
            // SPP only, for now (App\Jobs\SendQontakTemplateMessage, the
            // approved 'reminder_spp' template) - an official WhatsApp
            // Business line can only ever send an approved template to a
            // cold number, and this reminder is exactly that kind of cold
            // send. Every other fee type (uang_pangkal, jamiyyah, ekskul,
            // pendaftaran) stays on the free-text Sendago path below until
            // each gets its own approved template.
            $result = $this->queueSppReminderTemplate($bill, $guardian, $to);
        } else {
            $result = $this->queueWhatsApp($bill, $to, $data);
        }

        // Recorded whether or not the gateway accepted it. A failed send that is
        // retried tomorrow is better than a family messaged twice because the
        // first attempt was not written down.
        BillReminder::create([
            'bill_id' => $bill->id,
            'kind' => $kind,
            'channel' => $channel,
            'sent_to' => $to,
            'sent_at' => now(),
        ]);

        return $result->success;
    }

    /**
     * The guardian marked as the billing contact, falling back to the primary
     * one - a bill with no marked contact should still reach somebody.
     */
    private function billingContactFor(Bill $bill): ?Guardian
    {
        $guardians = $bill->student->guardians;

        return $guardians->firstWhere('pivot.is_billing_contact', true)
            ?? $guardians->firstWhere('pivot.is_primary', true)
            ?? $guardians->first();
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

    private function queueWhatsApp(Bill $bill, string $phone, array $data): NotificationResult
    {
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

    /**
     * Registers both banks' VA for this bill (idempotent - see
     * BillingApiGateway::ensureReminderVaPair()) and queues the approved
     * 'reminder_spp' template with both numbers, so the family can pay via
     * whichever bank they prefer without a second message. Body variables,
     * in order: nama anak, bulan tagihan, jumlah, VA Muamalat, kode bayar
     * BSI (the VA minus its fixed "3656" institution-code prefix - same
     * split the /pembayaran page's own BSI instructions use).
     */
    private function queueSppReminderTemplate(Bill $bill, Guardian $guardian, string $phone): NotificationResult
    {
        $templateId = config('services.qontak.spp_reminder_template_id');

        if (empty($templateId)) {
            Log::warning('[BillReminderSender] spp_reminder_template_id not configured, cannot send SPP reminder.');

            return NotificationResult::fail('Template reminder SPP Qontak belum dikonfigurasi.');
        }

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
        // Strips the fixed 4-digit institution code (3656) that prefixes
        // every BSI VA this school issues, leaving only the "kode bayar"
        // portion the template shows separately - see the config's own
        // institution_code comment for where that 4-digit value comes from.
        $bsiPaymentCode = mb_strlen($bsiVa) > 4 ? mb_substr($bsiVa, 4) : $bsiVa;

        $qontakPhone = '62'.substr($phone, 1);

        // Not updated to 'sent'/'failed' the way queueWhatsApp()'s log row
        // is - SendQontakTemplateMessage takes no notificationLogUlid (it is
        // shared across several notice types with no single owning log
        // convention, unlike SendOtpWhatsAppMessage). The send's real
        // success/failure lives in this job's own log line and queue retry
        // state instead; this row exists for a consistent per-bill send
        // history, same as every other channel logs here.
        NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => $phone,
            'payload' => [
                'student_name' => $bill->student->nama_lengkap,
                'period' => $bill->issued_at?->translatedFormat('F Y') ?? $bill->due_date->translatedFormat('F Y'),
                'amount' => number_format((float) $bill->remaining_amount, 0, ',', '.'),
                'va_muamalat' => $muamalatVa,
                'va_bsi_payment_code' => $bsiPaymentCode,
            ],
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        SendQontakTemplateMessage::dispatch(
            phone: $qontakPhone,
            toName: $guardian->nama ?: 'Orang Tua/Wali',
            templateId: $templateId,
            bodyValues: [
                $bill->student->nama_lengkap,
                $bill->issued_at?->translatedFormat('F Y') ?? $bill->due_date->translatedFormat('F Y'),
                number_format((float) $bill->remaining_amount, 0, ',', '.'),
                $muamalatVa,
                $bsiPaymentCode,
            ],
        );

        return NotificationResult::ok(['mode' => 'queued']);
    }

    private function log(Bill $bill, string $channel, string $to, array $data, NotificationResult $result): void
    {
        NotificationLog::create([
            'channel' => $channel,
            'template' => 'bill_reminder',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);
    }
}
