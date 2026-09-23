<?php

namespace App\Services\Billing;

use App\Jobs\SendQontakTemplateMessage;
use App\Models\Bill;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirms an SPP payment the moment PaymentAllocator::settle() marks it
 * completed - the 'reminder_spp_school' template asks for money, this one
 * ('receipt_spp_school') confirms it arrived. SPP only, same scope decision
 * as BillReminderSender::queueSppReminderTemplate() (every other fee type
 * has no approved template yet).
 *
 * One message per student, not per bill: a single payment can settle several
 * months of SPP at once (a parent catching up on back payments), and
 * periodLabel() collapses those into one "bulan {{2}}" line - either the one
 * month it actually is, or a range/list when there's more than one - rather
 * than firing a separate WhatsApp message per bill, which read as spam for
 * what the parent experienced as a single payment.
 */
class PaymentReceiptSender
{
    public function send(Payment $payment): void
    {
        $templateId = config('services.qontak.spp_receipt_template_id');

        if (empty($templateId)) {
            return;
        }

        $sppAllocations = PaymentAllocation::where('payment_id', $payment->id)
            ->with(['bill.feeType', 'bill.student.guardians'])
            ->get()
            ->filter(fn (PaymentAllocation $allocation) => $allocation->bill && $allocation->bill->feeType?->code === 'spp');

        // Bills for different students can in principle share one payment on
        // non-VA channels (VA-based checkouts are already restricted to a
        // single student - see CheckoutService::assertSingleVaGroupInBasket()) -
        // grouped so one sibling's months never leak into another's receipt.
        $byStudent = $sppAllocations->groupBy(fn (PaymentAllocation $allocation) => $allocation->bill->student_id);

        foreach ($byStudent as $studentAllocations) {
            try {
                $this->sendForStudent($payment, $studentAllocations, $templateId);
            } catch (Throwable $e) {
                // A receipt that fails to send must never undo the payment it
                // is confirming - settle() has already recorded the money.
                Log::warning('[PaymentReceiptSender] Failed to send receipt', [
                    'payment' => $payment->payment_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @param  Collection<int, PaymentAllocation>  $allocations  All of one student's SPP allocations from this payment. */
    private function sendForStudent(Payment $payment, Collection $allocations, string $templateId): void
    {
        $bills = $allocations->map(fn (PaymentAllocation $a) => $a->bill);
        $bill = $bills->first();
        $amount = (float) $allocations->sum('amount');

        $guardian = $this->billingContactFor($bill);
        $phone = $guardian?->no_hp;

        if (! $guardian || ! $phone) {
            Log::warning('[PaymentReceiptSender] No WhatsApp-reachable billing contact for receipt', [
                'bill_ids' => $bills->pluck('id')->all(),
            ]);

            return;
        }

        $qontakPhone = '62'.substr((string) $phone, 1);
        $bankName = $payment->gateway_response['bank_name'] ?? null;
        $method = $bankName ? "VA {$bankName}" : ucfirst(str_replace('_', ' ', (string) $payment->method));
        $period = $this->periodLabel($bills);
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
                'bill_ids' => $bills->pluck('id')->all(),
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

    /**
     * One month: "Agustus 2026". Several consecutive months (the common
     * back-payment case): "Agustus - Oktober 2026". Anything with a gap in
     * between (paid August and November but skipped the months between,
     * unusual but not impossible): every month named, so nothing paid goes
     * unmentioned.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function periodLabel(Collection $bills): string
    {
        $months = $bills
            ->map(fn (Bill $bill) => ($bill->issued_at ?? $bill->due_date)->copy()->startOfMonth())
            ->unique(fn (Carbon $date) => $date->format('Y-m'))
            ->sortBy(fn (Carbon $date) => $date->format('Y-m'))
            ->values();

        if ($months->count() === 1) {
            return $months->first()->translatedFormat('F Y');
        }

        $contiguous = true;

        for ($i = 1; $i < $months->count(); $i++) {
            if (! $months[$i]->isSameMonth($months[$i - 1]->copy()->addMonthNoOverflow())) {
                $contiguous = false;
                break;
            }
        }

        $first = $months->first();
        $last = $months->last();

        if ($contiguous) {
            return $first->year === $last->year
                ? $first->translatedFormat('F').' - '.$last->translatedFormat('F Y')
                : $first->translatedFormat('F Y').' - '.$last->translatedFormat('F Y');
        }

        return $months->map(fn (Carbon $date) => $date->translatedFormat('F Y'))->implode(', ');
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
