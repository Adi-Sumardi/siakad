<?php

namespace App\Services\Billing;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Tells a family their money arrived twice - the WhatsApp leg of the
 * overpayment handling (audit 2026-10-05).
 *
 * When two VAs for one bill are paid nearly simultaneously, the second
 * payment completes but settles nothing: its allocations are flagged
 * applies_to_bill=false and the excess waits on a TU refund. The normal
 * receipts still go out for both payments (both moved real money), so
 * without this message the family's only clue that half their money is
 * sitting in limbo is the refund appearing weeks later.
 *
 * One message per payment row (idempotent on NotificationLog, same
 * discipline as VaIssuedNotifier), always logged whether or not the gateway
 * accepted it. The retry sweep owns second chances through resend().
 */
class OverpaymentNotifier
{
    public function __construct(private WhatsAppGateway $whatsapp) {}

    public function notify(Payment $payment): NotificationResult
    {
        $overpayment = $payment->metadata['overpayment'] ?? null;

        if (! $overpayment) {
            return NotificationResult::ok(['skipped' => 'not-an-overpayment']);
        }

        $guardian = $payment->payer ?? $this->billingContactFor($payment);

        if (! $guardian) {
            Log::warning('[Overpayment] Payment has no guardian to notify', [
                'payment' => $payment->payment_number,
            ]);

            return NotificationResult::fail('Pembayaran tanpa wali untuk diberitahu.');
        }

        $data = $this->dataFor($payment, $guardian, $overpayment);

        if (NotificationLog::query()
            ->where('template', 'payment_overpayment')
            ->where('channel', 'whatsapp')
            ->where('notifiable_type', Payment::class)
            ->where('notifiable_id', $payment->id)
            ->exists()) {
            return NotificationResult::ok(['skipped' => 'already-notified']);
        }

        if (! filled($guardian->no_hp)) {
            // Same paper trail as VaIssuedNotifier: the monitoring screen
            // must say why the family never heard about their refund.
            NotificationLog::create([
                'channel' => 'whatsapp',
                'template' => 'payment_overpayment',
                'recipient' => null,
                'payload' => $data,
                'status' => 'failed',
                'error' => 'Kontak penagihan tidak punya nomor WhatsApp.',
                'notifiable_type' => Payment::class,
                'notifiable_id' => $payment->id,
            ]);

            return NotificationResult::fail('Kontak penagihan tidak punya nomor WhatsApp.');
        }

        // Queued, not sent inline - SendWhatsAppMessage throttles every
        // Sendago send. Best-effort either way: the overpayment itself is
        // already booked and alerted by the caller.
        $log = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'payment_overpayment',
            'recipient' => $guardian->no_hp,
            'payload' => $data,
            'status' => 'queued',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        try {
            SendWhatsAppMessage::dispatch((string) $guardian->no_hp, $this->message($data), $log->ulid);
        } catch (\Throwable $e) {
            // Under the sync driver (tests, local) the job runs inline and a
            // refused send throws here; the job has already marked the row.
            Log::warning('[Overpayment] Gagal terkirim', [
                'payment' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return NotificationResult::fail($e->getMessage());
        }

        return NotificationResult::ok(['mode' => 'queued']);
    }

    /**
     * The retry sweep's second chance. TU may clear metadata.overpayment
     * once the refund is done - that is this lane's signal that the message
     * is no longer true and must not go out again. Never writes a
     * NotificationLog row; the sweep updates the failed row in place.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $payment = $log->notifiable;

        if (! $payment instanceof Payment) {
            return NotificationResult::fail('Pembayaran sudah tidak ada.');
        }

        if ($payment->status !== 'completed') {
            return NotificationResult::fail("Pembayaran sudah {$payment->status} - pesan overpayment tidak relevan.");
        }

        $overpayment = $payment->metadata['overpayment'] ?? null;

        if (! $overpayment) {
            return NotificationResult::fail('Overpayment sudah tidak tercatat - kemungkinan refund selesai dan pesan tidak perlu dikirim ulang.');
        }

        $guardian = $payment->payer ?? $this->billingContactFor($payment);

        if (! $guardian) {
            return NotificationResult::fail('Pembayaran tanpa wali untuk diberitahu.');
        }

        // Re-resolve the CURRENT phone (audit 2026-10-05), same reason as
        // VaIssuedNotifier::resend(): a null frozen recipient must not turn
        // every retry into "Nomor WhatsApp tidak valid" until the attempt
        // budget runs out.
        if (! filled($guardian->no_hp)) {
            return NotificationResult::fail('Wali masih belum punya nomor WhatsApp - perbaiki data kontak dulu.');
        }

        return $this->whatsapp->sendMessage(
            (string) $guardian->no_hp,
            $this->message($this->dataFor($payment, $guardian, $overpayment)),
        );
    }

    /**
     * The guardian who paid, falling back to the billed student's billing
     * contact - same resolution order as the receipts (PaymentReceiptNotifier).
     */
    private function billingContactFor(Payment $payment): ?Guardian
    {
        $guardians = $payment->bills()->with('student.guardians')->get()
            ->flatMap(fn ($bill) => $bill->student?->guardians ?? collect())
            ->unique('id');

        return $guardians->firstWhere('pivot.is_billing_contact', true)
            ?? $guardians->firstWhere('pivot.is_primary', true)
            ?? $guardians->first();
    }

    /**
     * @param  array<string, mixed>  $overpayment
     * @return array<string, mixed>
     */
    private function dataFor(Payment $payment, Guardian $guardian, array $overpayment): array
    {
        $bills = $payment->bills()->with('student')->get();
        $gateway = $payment->gateway_response ?? [];

        return [
            'guardian_name' => $guardian->nama,
            'student_name' => $bills->first()?->student?->nama_lengkap ?? 'ananda',
            'bills' => $bills->pluck('description')->all(),
            'bank_name' => (string) ($gateway['bank_name'] ?? ''),
            'va_number' => $payment->referenceNumber(),
            'amount' => number_format((float) $payment->amount, 0, ',', '.'),
            'excess' => number_format(
                (float) collect($overpayment['bills'] ?? [])->sum('allocation_amount'),
                0, ',', '.',
            ),
            'payment_number' => $payment->payment_number,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function message(array $data): string
    {
        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            ."Kami menerima pembayaran untuk {$data['student_name']} melalui Virtual Account {$data['bank_name']}\n"
            ."sebesar Rp {$data['amount']} (ref. {$data['va_number']}).\n\n"
            .'Tagihan terkait ('.implode('; ', $data['bills']).") sudah lunas dari pembayaran sebelumnya, "
            ."sehingga pembayaran ini terdeteksi sebagai PEMBAYARAN GANDA.\n\n"
            ."Kelebihan sebesar Rp {$data['excess']} TIDAK dihitung sebagai pembayaran tagihan dan akan "
            ."diproses pengembalian dananya (refund) oleh Tata Usaha. Tim TU akan menghubungi "
            ."Bapak/Ibu untuk proses lebih lanjut.\n\n"
            .'Mohon simpan pesan ini sebagai catatan. Terima kasih.';
    }
}
