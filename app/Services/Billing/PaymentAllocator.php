<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of a bill's paid_amount, remaining_amount and status.
 *
 * Everything is derived from payment_allocations, recomputed in full each time,
 * never incremented. PMB tracked payment state in four places that were kept in
 * step by hand and drifted apart; here there is one source and one function
 * that reads it, so "how much has this bill received" cannot have two answers.
 *
 * Only allocations belonging to a *completed* payment count. A checkout that is
 * still pending has reserved nothing: the parent may abandon it, and a bill
 * marked paid on the strength of an unfinished Xendit invoice is a bill nobody
 * chases.
 */
class PaymentAllocator
{
    /**
     * Records what a payment is meant to settle.
     *
     * @param  array<int, float>  $amountsByBillId
     */
    public function allocate(Payment $payment, array $amountsByBillId): void
    {
        $total = round(array_sum($amountsByBillId), 2);

        if (abs($total - (float) $payment->amount) > 0.01) {
            // Allowing these to differ would let a payment settle more (or less)
            // than the money that actually moved.
            throw new RuntimeException(
                "Total alokasi (Rp {$total}) tidak sama dengan jumlah pembayaran (Rp {$payment->amount})."
            );
        }

        DB::transaction(function () use ($payment, $amountsByBillId) {
            foreach ($amountsByBillId as $billId => $amount) {
                PaymentAllocation::updateOrCreate(
                    ['payment_id' => $payment->id, 'bill_id' => $billId],
                    ['amount' => round($amount, 2)],
                );
            }
        });

        $this->recomputeFor(array_keys($amountsByBillId));
    }

    /**
     * Marks a payment settled and updates every bill it touched.
     *
     * Idempotent on purpose: a Xendit callback can arrive twice, and the second
     * one must change nothing rather than double-count.
     *
     * Refuses anything not still pending/processing, not only what is already
     * completed. A payment CheckoutService superseded because a fresher
     * checkout covered the same bill is done, even if its old Xendit invoice
     * is technically still sitting out there and gets paid late - completing
     * it here would double-count the bill exactly the way two live invoices
     * for one bill did before checkout started superseding them.
     */
    public function settle(Payment $payment, ?string $externalId = null, array $gatewayResponse = []): void
    {
        if (! in_array($payment->status, ['pending', 'processing'], true)) {
            return;
        }

        DB::transaction(function () use ($payment, $externalId, $gatewayResponse) {
            $payment->forceFill([
                'status' => 'completed',
                'paid_at' => $payment->paid_at ?? now(),
                'external_transaction_id' => $externalId ?? $payment->external_transaction_id,
                'gateway_response' => $gatewayResponse ?: $payment->gateway_response,
            ])->save();
        });

        $this->recomputeFor($payment->allocations()->pluck('bill_id')->all());
    }

    /** A failed or expired checkout releases the bills it was holding. */
    public function fail(Payment $payment, string $status = 'failed', ?string $reason = null): void
    {
        if ($payment->isSettled()) {
            // Never walk back money that already arrived - that is a refund,
            // which is a different operation with different bookkeeping.
            return;
        }

        $payment->forceFill([
            'status' => $status,
            'failed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->recomputeFor($payment->allocations()->pluck('bill_id')->all());
    }

    /** @param  list<int>  $billIds */
    public function recomputeFor(array $billIds): void
    {
        Bill::whereIn('id', array_unique($billIds))->get()->each(fn (Bill $bill) => $this->recompute($bill));
    }

    public function recompute(Bill $bill): void
    {
        // Cancelled and waived bills are decisions, not arithmetic: recomputing
        // them from allocations would silently reopen a bill an admin closed.
        if (in_array($bill->status, ['cancelled', 'waived'], true)) {
            return;
        }

        $paid = (float) PaymentAllocation::where('bill_id', $bill->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'completed'))
            ->sum('amount');

        $total = (float) $bill->total_amount;
        // Floored at zero: an overpayment must not show as a negative balance
        // owed, which is how PMB's progress bar once exceeded 100%.
        $remaining = round(max(0, $total - $paid), 2);

        $status = match (true) {
            $remaining <= 0 => 'paid',
            $paid > 0 => 'partial',
            $bill->due_date->isPast() => 'overdue',
            default => 'unpaid',
        };

        $bill->forceFill([
            'paid_amount' => round($paid, 2),
            'remaining_amount' => $remaining,
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($bill->paid_at ?? now()) : null,
        ])->save();
    }
}
