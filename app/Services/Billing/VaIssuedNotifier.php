<?php

namespace App\Services\Billing;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Tells a family their Virtual Account number - the WhatsApp leg of an
 * admin-issued VA ("Buat VA" on the tagihan page).
 *
 * A wali who checks out themselves is already looking at the number; a VA an
 * admin mints is not, so without this message the family would only find it
 * by opening the app. One message per payment row (idempotent on
 * NotificationLog, same discipline as PaymentReceiptNotifier), always logged
 * whether or not the gateway accepted it - a send nobody wrote down gets
 * retried as a duplicate.
 *
 * This is a new template, not the receipt's dropped WhatsApp leg: receipts
 * stay email-only by the school's decision.
 */
class VaIssuedNotifier
{
    public function __construct(private WhatsAppGateway $whatsapp) {}

    public function notify(Payment $payment): NotificationResult
    {
        $guardian = $payment->payer;

        if (! $guardian) {
            Log::warning('[VaIssued] Payment has no payer guardian to notify', [
                'payment' => $payment->payment_number,
            ]);

            return NotificationResult::fail('Pembayaran tanpa wali untuk diberitahu.');
        }

        $data = $this->dataFor($payment, $guardian);

        if (NotificationLog::query()
            ->where('template', 'va_issued')
            ->where('channel', 'whatsapp')
            ->where('notifiable_type', Payment::class)
            ->where('notifiable_id', $payment->id)
            ->exists()) {
            return NotificationResult::ok(['skipped' => 'already-notified']);
        }

        if (! filled($guardian->no_hp)) {
            // A contact with no phone cannot be pushed anything - the log row
            // is the paper trail so the monitoring screen says why the family
            // never heard.
            NotificationLog::create([
                'channel' => 'whatsapp',
                'template' => 'va_issued',
                'recipient' => null,
                'payload' => $data,
                'status' => 'failed',
                'error' => 'Kontak penagihan tidak punya nomor WhatsApp.',
                'notifiable_type' => Payment::class,
                'notifiable_id' => $payment->id,
            ]);

            return NotificationResult::fail('Kontak penagihan tidak punya nomor WhatsApp.');
        }

        // Best-effort by design: a gateway crash must never take the VA
        // issuance down with it - the payment row and its VA already exist.
        try {
            $result = $this->whatsapp->sendMessage($guardian->no_hp, $this->message($data));
        } catch (\Throwable $e) {
            Log::warning('[VaIssued] Gagal menyiapkan pengiriman', [
                'payment' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            NotificationLog::create([
                'channel' => 'whatsapp',
                'template' => 'va_issued',
                'recipient' => $guardian->no_hp,
                'payload' => $data,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'notifiable_type' => Payment::class,
                'notifiable_id' => $payment->id,
            ]);

            return NotificationResult::fail($e->getMessage());
        }

        NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'va_issued',
            'recipient' => $guardian->no_hp,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        $result->success
            ? Log::info('[VaIssued] Terkirim', [
                'payment' => $payment->payment_number,
                'to' => $guardian->no_hp,
            ])
            : Log::warning('[VaIssued] Gagal terkirim', [
                'payment' => $payment->payment_number,
                'error' => $result->message,
            ]);

        return $result;
    }

    /**
     * The retry sweep's second chance. The payment must still be open - a VA
     * that has since settled or been superseded must not be re-pushed as if
     * it were still awaiting payment. Never writes a NotificationLog row; the
     * sweep updates the failed row in place.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $payment = $log->notifiable;

        if (! $payment instanceof Payment) {
            return NotificationResult::fail('Pembayaran sudah tidak ada.');
        }

        if (! in_array($payment->status, ['pending', 'processing'], true)) {
            return NotificationResult::fail("Pembayaran sudah {$payment->status} - VA tidak perlu dikirim ulang.");
        }

        if (! $payment->payer) {
            return NotificationResult::fail('Pembayaran tanpa wali untuk diberitahu.');
        }

        return $this->whatsapp->sendMessage(
            $log->recipient ?? '',
            $this->message($this->dataFor($payment, $payment->payer)),
        );
    }

    /** @return array<string, mixed> */
    private function dataFor(Payment $payment, Guardian $guardian): array
    {
        $bills = $payment->bills()->with('student')->get();
        $gateway = $payment->gateway_response ?? [];

        return [
            'guardian_name' => $guardian->nama,
            'student_name' => $bills->first()?->student?->nama_lengkap ?? 'ananda',
            'bills' => $bills->pluck('description')->all(),
            'bank_name' => (string) ($gateway['bank_name'] ?? ''),
            'va_number' => (string) ($gateway['va_number'] ?? ''),
            'amount' => number_format((float) $payment->amount, 0, ',', '.'),
            'due_date' => ($payment->expires_at ?? now())->translatedFormat('d F Y'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function message(array $data): string
    {
        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            ."Virtual Account pembayaran untuk {$data['student_name']} telah dibuat:\n\n"
            ."Tagihan: ".implode('; ', $data['bills'])."\n"
            ."Bank: {$data['bank_name']}\n"
            ."Nomor VA: {$data['va_number']}\n"
            ."Jumlah: Rp {$data['amount']}\n"
            ."Bayar sebelum: {$data['due_date']}\n\n"
            .'Nomor ini juga tersedia di aplikasi sekolah. Abaikan pesan ini bila sudah dibayar.';
    }
}
