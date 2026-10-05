<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
 * marked paid on the strength of an unfinished bank invoice is a bill nobody
 * chases.
 */
class PaymentAllocator
{
    public function __construct(
        private PaymentReceiptNotifier $receipts,
        private PaymentReceiptSender $whatsappReceipts,
        private OverpaymentNotifier $overpayments,
    ) {}

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
     * Idempotent on purpose: a bank callback can arrive twice, and the second
     * one must change nothing rather than double-count.
     *
     * Refuses anything not still pending/processing, not only what is already
     * completed. A payment CheckoutService superseded because a fresher
     * checkout covered the same bill is done, even if its old bank invoice
     * is technically still sitting out there and gets paid late - completing
     * it here would double-count the bill exactly the way two live invoices
     * for one bill did before checkout started superseding them.
     *
     * Race-proof across DIFFERENT payment rows (audit 2026-10-05): the
     * reminder pair deliberately had two banks' VAs live for one bill, and
     * two near-simultaneous payments on that pair each claimed their OWN row
     * (two rows, two claims) and both completed - paid_amount overshot the
     * total and max(0, ...) buried the excess. Now the claim transaction
     * also locks the bills themselves (ordered by id, so two settles on the
     * same bills serialize instead of deadlocking) and, still inside that
     * lock, flags any allocation the bill can no longer absorb
     * applies_to_bill=false: the second payment still completes (the money
     * really arrived) but settles nothing - its excess is recorded on
     * metadata.overpayment, never on paid_amount, and the caller learns
     * through the SettlementResult so the family gets told about the refund
     * instead of discovering it weeks later.
     */
    public function settle(Payment $payment, ?string $externalId = null, array $gatewayResponse = []): SettlementResult
    {
        // The claim is one conditional, locked UPDATE (audit T39-a): the
        // old in-memory status check let the webhook and the poller - two
        // snapshots of the same row - both pass it and both run the full
        // settle, double-sending receipts. The loser here changes nothing
        // and sends nothing.
        $outcome = DB::transaction(function () use ($payment, $externalId, $gatewayResponse) {
            $fresh = Payment::query()
                ->whereKey($payment->id)
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return null;
            }

            // Lock the bills this payment touches, ordered by id so any two
            // concurrent settles on overlapping bills take the same lock
            // order and serialize instead of deadlocking. This is the actual
            // two-row race fix: both payments can claim their own row, but
            // only one at a time may decide what these bills still owe.
            $allocations = $fresh->allocations()->orderBy('bill_id')->get();

            $bills = Bill::query()
                ->whereIn('id', $allocations->pluck('bill_id')->unique()->values()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $overpayment = [];

            foreach ($allocations as $allocation) {
                $bill = $bills->firstWhere('id', $allocation->bill_id);

                if (! $bill) {
                    continue;
                }

                // Current read, deliberately not a plain aggregate: under
                // MySQL REPEATABLE READ an unforced SELECT sums the
                // transaction snapshot and can miss the sibling settle that
                // committed while this transaction waited on the bill lock -
                // resurrecting this exact bug in production only (SQLite,
                // which the test suite runs on, ignores FOR UPDATE
                // entirely). Row counts per bill are tiny; hydrating them is
                // nothing.
                $appliedElsewhere = (float) PaymentAllocation::query()
                    ->where('bill_id', $bill->id)
                    ->where('applies_to_bill', true)
                    ->where('payment_id', '!=', $fresh->id)
                    ->whereHas('payment', fn ($q) => $q->where('status', 'completed'))
                    ->lockForUpdate()
                    ->get()
                    ->sum('amount');

                $headroom = round(max(0, (float) $bill->total_amount - $appliedElsewhere), 2);

                // Full-count-or-nothing per row: a partly-absorbable
                // allocation leaves the remainder visible on the bill
                // (conservative) rather than paid past its total (hidden).
                // The allocation row itself is NEVER deleted - see the
                // migration that added this flag for every reader that
                // would silently lose the payment.
                if ((float) $allocation->amount > $headroom + 0.01) {
                    $allocation->forceFill(['applies_to_bill' => false])->save();

                    $overpayment['bills'][$bill->ulid] = [
                        'allocation_amount' => (float) $allocation->amount,
                        'headroom' => $headroom,
                    ];
                }
            }

            // Merged, never replaced (audit T38-b): the webhook and poller
            // payloads carry verification data but not va_number/bank_name,
            // and a wholesale overwrite dropped those keys - every receipt
            // surface then fell back to the internal payment_number instead
            // of the VA, the exact drift ad095e closed. Passing keys win,
            // everything already recorded survives.
            // Merged first so the channel backfill below reads the union -
            // the bank name often lives on the payment's OWN registration
            // payload, not on the webhook/poller verification data.
            $mergedResponse = array_merge($fresh->gateway_response ?? [], $gatewayResponse);

            $forceFill = [
                'status' => 'completed',
                'paid_at' => $fresh->paid_at ?? now(),
                'external_transaction_id' => $externalId ?? $fresh->external_transaction_id,
                'gateway_response' => $mergedResponse,
                // The public receipt token is born atomically with the
                // claim (feature batch Poin 11C): only a settled payment
                // ever gets one, exactly once, and the settle dedup and the
                // token mint share a single conditional UPDATE - there is
                // no window where two lanes mint two tokens. The bank name
                // backfill makes the channel filterable relationally later
                // (gateway_response is never JSON-queried).
                'receipt_public_token' => $fresh->receipt_public_token ?? Str::random(32),
                'channel' => $fresh->channel ?? ($mergedResponse['bank_name'] ?? null),
            ];

            if ($overpayment !== []) {
                // Same merge discipline as gateway_response: an overpayment
                // annotation must not clobber whatever else metadata holds,
                // and TU clearing it after the refund simply overwrites the
                // key next time something writes here.
                $forceFill['metadata'] = array_merge($fresh->metadata ?? [], [
                    'overpayment' => $overpayment + ['detected_at' => now()->toIso8601String()],
                ]);
            }

            $fresh->forceFill($forceFill)->save();

            // Inside the lock on purpose (used to run after commit): the
            // bill row is still held, so claim -> flag -> recompute cannot
            // interleave with a sibling settle's own pass over these bills.
            $bills->each(fn (Bill $bill) => $this->recompute($bill));

            return ['overpayment' => $overpayment];
        });

        if ($outcome === null) {
            return SettlementResult::notClaimed();
        }

        $payment->refresh();

        $excess = round((float) collect($outcome['overpayment']['bills'] ?? [])->sum('allocation_amount'), 2);

        if ($excess > 0) {
            // Lane-independent TU alarm: the webhook also marks its
            // integration_event failed (visible on the monitoring screen),
            // but the poller and checkout-stale lanes land here only.
            Log::critical('[PaymentAllocator] Double payment booked as overpayment - the bill was already settled by another VA, TU owes the family a refund', [
                'payment' => $payment->payment_number,
                'amount' => (float) $payment->amount,
                'excess' => $excess,
                'bills' => array_keys($outcome['overpayment']['bills'] ?? []),
            ]);
        }

        // The sibling-VA choke point (audit 25-9, sharpens T39): settling
        // one live VA must kill every OTHER still-live payment sharing these
        // bills, whatever lane created them and whatever settles first. It
        // was born when the reminder flow still ran two banks at once (the
        // pair removed 2026-10-05), and it reuses an already-live checkout
        // VA - so the settled payment often carries no 'spp_reminder' marker
        // at all, which is why this guard may not key off metadata.source
        // (the old poller-only, source-matched guard let exactly that case
        // through: family pays the checkout Muamalat VA, webhook settles it,
        // nothing kills the BSI half, family pays that too - two completed
        // payments, one bill, the overpayment swallowed by max(0, ...) in
        // recompute()). Cross-bank pairs still arise today via an abandoned
        // checkout bank-switch (expireVa is broken at e-SPP, so the old VA
        // stays payable), so this stays load-bearing alongside settle()'s
        // own overpayment guard, which catches whatever slips through here.
        // Webhook and poller both land here, so one implementation covers
        // every settle lane. Best-effort on purpose: the money is already
        // booked, and a sibling cleanup hiccup must never fail the settle
        // itself.
        try {
            $this->supersedeSiblingVaPayments($payment);
        } catch (Throwable $e) {
            Log::warning('[PaymentAllocator] Sibling VA supersede after settle failed: '.$e->getMessage(), [
                'payment' => $payment->payment_number,
            ]);
        }

        // Best-effort by design, and each channel on its own: money that
        // already arrived is never rolled back because a gateway hiccuped,
        // and the email receipt failing never silences the WhatsApp one (or
        // the other way round).
        try {
            $this->receipts->notify($payment->fresh());
        } catch (Throwable $e) {
            Log::warning('[PaymentAllocator] Email receipt failed: '.$e->getMessage(), [
                'payment' => $payment->payment_number,
            ]);
        }

        try {
            $this->whatsappReceipts->send($payment->fresh());
        } catch (Throwable $e) {
            Log::warning('[PaymentAllocator] WhatsApp receipt failed: '.$e->getMessage(), [
                'payment' => $payment->payment_number,
            ]);
        }

        // The family must hear about the double payment from us, not from
        // their bank statement weeks later: this is the message that says
        // the money arrived, settles nothing, and a refund is coming. Same
        // best-effort discipline as the receipts - the overpayment is
        // already booked and alerted by the time this runs.
        if ($excess > 0) {
            try {
                $this->overpayments->notify($payment->fresh());
            } catch (Throwable $e) {
                Log::warning('[PaymentAllocator] Overpayment WhatsApp failed: '.$e->getMessage(), [
                    'payment' => $payment->payment_number,
                ]);
            }
        }

        return SettlementResult::settled($outcome['overpayment'], $excess);
    }

    /** A failed or expired checkout releases the bills it was holding. */
    public function fail(Payment $payment, string $status = 'failed', ?string $reason = null): void
    {
        // Same locked conditional claim as settle() (audit T39-a): the old
        // isSettled() read decided on a possibly-stale snapshot - a fail()
        // racing a settle() could flip a completed payment back to failed,
        // reopening a bill the money had already closed.
        $claimed = DB::transaction(function () use ($payment, $status, $reason) {
            $fresh = Payment::query()
                ->whereKey($payment->id)
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                // Never walk back money that already arrived - that is a
                // refund, which is a different operation with different
                // bookkeeping. Also no-ops on an already-failed row.
                return false;
            }

            $fresh->forceFill([
                'status' => $status,
                'failed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $this->recomputeFor($payment->allocations()->pluck('bill_id')->all());
    }

    /**
     * Fails (and best-effort expires at the bank) every OTHER still-pending
     * or still-processing payment allocated to the bills this settle touched
     * - see settle()'s own comment for why this is the choke point and why
     * it must not filter on metadata.source. Resolved from the container
     * rather than constructor injection: PaymentAllocator is constructed
     * inside BillingApiGateway's own dependency graph, so taking the
     * gateway by injection here would be circular.
     */
    private function supersedeSiblingVaPayments(Payment $settled): void
    {
        $billIds = $settled->allocations()->pluck('bill_id')->all();

        if ($billIds === []) {
            return;
        }

        $siblingIds = PaymentAllocation::whereIn('bill_id', $billIds)
            ->pluck('payment_id')
            ->unique()
            ->reject(fn ($id) => $id === $settled->id);

        if ($siblingIds->isEmpty()) {
            return;
        }

        Payment::whereIn('id', $siblingIds)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($q) {
                $q->whereIn('gateway_response->provider', ['bank_muamalat', 'bank_bsi'])
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->get()
            ->each(function (Payment $sibling) {
                $this->fail($sibling, 'failed', 'Digantikan - tagihan yang sama sudah lunas lewat VA bank lain.');

                // Same best-effort shrink at e-SPP as every other supersede
                // lane: unreachable banks must not block the local fail,
                // which is what actually stops double-issuing here.
                $provider = $sibling->gateway_response['provider'] ?? null;

                if (in_array($provider, ['bank_muamalat', 'bank_bsi'], true)) {
                    app(\App\Services\Payment\BillingApiGateway::class)->expireVa($sibling);
                }
            });
    }

    /** @param  list<int>  $billIds */
    public function recomputeFor(array $billIds): void
    {
        // Ordered by id so every multi-bill recompute touches bills in the
        // same order settle() takes its locks - deterministic lock ordering.
        Bill::whereIn('id', array_unique($billIds))->orderBy('id')->get()->each(fn (Bill $bill) => $this->recompute($bill));
    }

    public function recompute(Bill $bill): void
    {
        // Cancelled and waived bills are decisions, not arithmetic: recomputing
        // them from allocations would silently reopen a bill an admin closed.
        if (in_array($bill->status, ['cancelled', 'waived'], true)) {
            return;
        }

        // applies_to_bill=false rows are the double-booked half of two
        // near-simultaneous VA payments (see settle()): real money that
        // settles nothing, waiting on a TU refund - counting it here is the
        // exact overcounting this flag exists to prevent. Current read via
        // lockForUpdate for the same REPEATABLE READ reason as settle()'s
        // headroom query; a no-op outside a transaction and on SQLite.
        $paid = (float) PaymentAllocation::where('bill_id', $bill->id)
            ->where('applies_to_bill', true)
            ->whereHas('payment', fn ($q) => $q->where('status', 'completed'))
            ->lockForUpdate()
            ->get()
            ->sum('amount');

        $total = (float) $bill->total_amount;
        // Floored at zero: an overpayment must not show as a negative balance
        // owed, which is how PMB's progress bar once exceeded 100%.
        $remaining = round(max(0, $total - $paid), 2);

        // Overdue outranks partial - "still owing, past due" is the more
        // urgent truth, and ranking it this way makes this recompute write
        // exactly what bills:mark-overdue writes. The old order (partial
        // first) flipped a partly-paid overdue bill back to 'partial' on
        // every payment event and back to 'overdue' every night, so
        // tunggakan counts and reminders oscillated with whoever wrote last.
        // paid_amount still carries the partial payment either way.
        // Comparison mirrors the sweep: strictly before the start of today,
        // so a bill due today is not overdue until tomorrow.
        $status = match (true) {
            $remaining <= 0 => 'paid',
            $bill->due_date->lt(now()->startOfDay()) => 'overdue',
            $paid > 0 => 'partial',
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
