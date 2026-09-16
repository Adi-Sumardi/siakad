<?php

namespace App\Services\Billing;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Support\Facades\Log;

/**
 * Tells a family their money arrived.
 *
 * Fired from PaymentAllocator::settle(), the one choke point every settle
 * path funnels through - the e-SPP webhook, the poller, the dev simulate
 * button, a staff cash record - so however a payment completed, the receipt
 * is the same message from the same code. Idempotent on NotificationLog:
 * the webhook can arrive twice and settle() can run again, the family still
 * hears it exactly once (same discipline BillReminderSender keeps so a job
 * that runs twice cannot message twice).
 */
class PaymentReceiptNotifier
{
    public function __construct(
        private MailGateway $mail,
        private WhatsAppGateway $whatsapp,
    ) {}

    public function notify(Payment $payment): void
    {
        if (NotificationLog::query()
            ->where('template', 'payment_receipt')
            ->where('notifiable_type', Payment::class)
            ->where('notifiable_id', $payment->id)
            ->exists()) {
            return;
        }

        $guardian = $payment->payer ?? $this->billingContactFor($payment);

        if (! $guardian) {
            // Not worth failing the settle over - the money is in; an admin
            // can see the payment regardless. Same stance BillReminderSender
            // takes for a bill with no contact.
            Log::warning('[PaymentReceipt] Payment has no guardian to receipt', [
                'payment' => $payment->payment_number,
            ]);

            return;
        }

        $channel = $guardian->email ? 'email' : 'whatsapp';
        $to = $guardian->email ?: (string) $guardian->no_hp;

        if (! $to) {
            return;
        }

        $data = $this->dataFor($payment, $guardian);

        $result = $channel === 'email'
            ? $this->mail->send($to, 'payment_receipt', $data)
            : $this->whatsapp->sendMessage($to, $this->whatsappMessage($data));

        // Recorded whether or not the gateway accepted it, for the same
        // reason as the reminders: a send nobody wrote down gets retried as
        // a duplicate.
        NotificationLog::create([
            'channel' => $channel,
            'template' => 'payment_receipt',
            'recipient' => $to,
            'payload' => $data,
            'status' => $result->success ? 'sent' : 'failed',
            'error' => $result->message,
            'sent_at' => $result->success ? now() : null,
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
        ]);
    }

    /**
     * The retry sweep's second chance for a receipt whose delivery failed.
     * The data is re-derived from the payment itself, so it always states
     * what actually happened; the delivery target stays frozen - the row's
     * recipient is who the first attempt went to and who is still waiting
     * for the confirmation (if the contact has since changed, the greeting
     * name may differ from the address; that is cosmetic, the target is
     * not). Never writes a NotificationLog row; the sweep updates the
     * failed row in place.
     */
    public function resend(NotificationLog $log): NotificationResult
    {
        $payment = $log->notifiable;

        if (! $payment instanceof Payment) {
            return NotificationResult::fail('Pembayaran sudah tidak ada.');
        }

        $guardian = $payment->payer ?? $this->billingContactFor($payment);

        if (! $guardian) {
            return NotificationResult::fail('Pembayaran tanpa wali untuk diberitahu.');
        }

        $data = $this->dataFor($payment, $guardian);

        return $log->channel === 'email'
            ? $this->mail->send($log->recipient, 'payment_receipt', $data)
            : $this->whatsapp->sendMessage($log->recipient, $this->whatsappMessage($data));
    }

    /**
     * The guardian who paid, falling back to the billed student's billing
     * contact - a staff-recorded cash payment may carry no payer at all.
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

    /** @return array<string, mixed> */
    private function dataFor(Payment $payment, Guardian $guardian): array
    {
        $bills = $payment->bills()->with('student')->get();
        $gateway = $payment->gateway_response ?? [];

        return [
            'guardian_name' => $guardian->nama,
            'student_name' => $bills->first()?->student?->nama_lengkap ?? 'ananda',
            'payment_number' => $payment->payment_number,
            'amount' => number_format((float) $payment->amount, 0, ',', '.'),
            'paid_at' => ($payment->paid_at ?? now())->translatedFormat('d F Y, H:i'),
            'bank_name' => (string) ($gateway['bank_name'] ?? ''),
            'va_number' => (string) ($gateway['va_number'] ?? ''),
            'method' => (string) ($payment->method ?? ''),
            'bills' => $bills->pluck('description')->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function whatsappMessage(array $data): string
    {
        $channel = $data['va_number'] !== ''
            ? 'Virtual Account '.$data['bank_name'].' ('.$data['va_number'].')'
            : match ($data['method']) {
                'cash' => 'Tunai di Tata Usaha',
                'transfer' => 'Transfer (diverifikasi Tata Usaha)',
                default => $data['method'] !== '' ? $data['method'] : 'Verifikasi Tata Usaha',
            };

        $billLines = collect($data['bills'])
            ->map(fn (string $desc) => '- '.$desc)
            ->implode("\n");

        return "Assalamu'alaikum {$data['guardian_name']},\n\n"
            ."Alhamdulillah, pembayaran untuk {$data['student_name']} telah kami terima "
            ."({$data['paid_at']}).\n\n"
            ."Total dibayar: Rp {$data['amount']}\n"
            ."Metode: {$channel}\n"
            ."Nomor Pembayaran: {$data['payment_number']}\n\n"
            ."Tagihan yang lunas:\n{$billLines}\n\n"
            .'Terima kasih.';
    }
}
