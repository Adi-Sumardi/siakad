<?php

namespace App\Services\Billing;

use App\Jobs\SendQontakTemplateMessage;
use App\Models\Bill;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirms an SPP payment the moment PaymentAllocator::settle() marks it
 * completed - the 'reminder_spp_school' template asks for money, this one
 * ('receipt_spp_school') confirms it arrived. SPP only, same scope decision
 * as BillReminderSender::queueSppReminderTemplate() (every other fee type
 * has no approved template yet).
 *
 * One message per bill, not per payment: a single payment can settle several
 * bills at once (a parent paying two months of SPP together), and each gets
 * its own "bulan {{2}}" line - sending one combined message would need a
 * template this app doesn't have.
 */
class PaymentReceiptSender
{
    public function send(Payment $payment): void
    {
        $templateId = config('services.qontak.spp_receipt_template_id');

        if (empty($templateId)) {
            return;
        }

        $allocations = PaymentAllocation::where('payment_id', $payment->id)
            ->with(['bill.feeType', 'bill.student.guardians'])
            ->get();

        foreach ($allocations as $allocation) {
            $bill = $allocation->bill;

            if (! $bill || $bill->feeType?->code !== 'spp') {
                continue;
            }

            try {
                $this->sendForBill($payment, $bill, (float) $allocation->amount, $templateId);
            } catch (Throwable $e) {
                // A receipt that fails to send must never undo the payment it
                // is confirming - settle() has already recorded the money.
                Log::warning('[PaymentReceiptSender] Failed to send receipt', [
                    'payment' => $payment->payment_number,
                    'bill' => $bill->bill_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendForBill(Payment $payment, Bill $bill, float $amount, string $templateId): void
    {
        $guardian = $this->billingContactFor($bill);
        $phone = $guardian?->no_hp;

        if (! $guardian || ! $phone) {
            Log::warning('[PaymentReceiptSender] No WhatsApp-reachable billing contact for receipt', [
                'bill' => $bill->bill_number,
            ]);

            return;
        }

        $qontakPhone = '62'.substr((string) $phone, 1);
        $bankName = $payment->gateway_response['bank_name'] ?? null;
        $method = $bankName ? "VA {$bankName}" : ucfirst(str_replace('_', ' ', (string) $payment->method));
        $period = $bill->issued_at?->translatedFormat('F Y') ?? $bill->due_date->translatedFormat('F Y');
        $paidAt = $payment->paid_at?->translatedFormat('d F Y, H.i') ?? now()->translatedFormat('d F Y, H.i');

        NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'receipt_spp_school',
            'recipient' => $phone,
            'payload' => [
                'student_name' => $bill->student->nama_lengkap,
                'period' => $period,
                'amount' => number_format($amount, 0, ',', '.'),
                'paid_at' => $paidAt,
                'method' => $method,
                'reference' => $payment->payment_number,
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
                $period,
                number_format($amount, 0, ',', '.'),
                $paidAt,
                $method,
                $payment->payment_number,
            ],
        );
    }

    /** Same fallback chain as BillReminderSender::billingContactFor(). */
    private function billingContactFor(Bill $bill): ?Guardian
    {
        $guardians = $bill->student->guardians;

        return $guardians->firstWhere('pivot.is_billing_contact', true)
            ?? $guardians->firstWhere('pivot.is_primary', true)
            ?? $guardians->first();
    }
}
