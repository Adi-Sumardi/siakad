<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repairs bills that were double-booked BEFORE the applies_to_bill flag
 * existed (audit 2026-10-05 P1).
 *
 * The flag landed with a default of true, so historical double settlements
 * still show paid_amount past total_amount with no overpayment annotation
 * and no entry on TU's refund worklist. This command re-derives each
 * affected bill the same way settle() now does live: allocations of
 * completed payments, in payment order, count until the bill is full -
 * everything after that is flagged unapplied and its payment annotated.
 *
 * Money-touching like every billing tool here: dry-run by default, --apply
 * to write. Deterministic: safe to re-run (already-flagged rows count as
 * nothing and drop out of the candidate set).
 */
class ReconcileOverpayments extends Command
{
    protected $signature = 'payments:reconcile-overpayments {--apply : Tulis perbaikan (tanpa ini: hanya pratinjau)}';

    protected $description = 'Tandai overpayment historis (tagihan terbayar dobel sebelum flag applies_to_bill) sebagai tidak berlaku';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Candidate bills: applied allocations of completed payments sum
        // past the bill's total. Applied-vs-not is exactly what this run
        // decides, so candidates are computed from the raw set.
        $bills = Bill::query()
            ->whereNotIn('status', ['cancelled', 'waived'])
            ->whereHas('allocations.payment', fn ($q) => $q->where('status', 'completed'))
            ->with(['allocations' => fn ($q) => $q
                ->whereHas('payment', fn ($p) => $p->where('status', 'completed')),
                'allocations.payment'])
            ->get()
            ->filter(function (Bill $bill) {
                $applied = (float) $bill->allocations->where('applies_to_bill', true)->sum('amount');

                return $applied > round((float) $bill->total_amount, 2) + 0.01;
            });

        if ($bills->isEmpty()) {
            $this->info('Tidak ada tagihan yang tercatat dobel - tidak ada yang perlu direkonsiliasi.');

            return self::SUCCESS;
        }

        $owedTotal = 0.0;

        foreach ($bills as $bill) {
            // Walk the applied allocations in SETTLEMENT order (audit r2):
            // paid_at first, payment id as the tiebreak. Creation order
            // (payment_id alone) routinely differs from settlement order -
            // webhook-vs-poller latency - and flagging the wrong row puts
            // the WRONG payment on TU's refund worklist (totals stay
            // right, attribution goes to the family that actually paid on
            // time).
            $room = round((float) $bill->total_amount, 2);

            $inOrder = $bill->allocations
                ->where('applies_to_bill', true)
                ->values()
                ->sort(fn ($a, $b) => [
                    $a->payment?->paid_at?->timestamp ?? 0,
                    $a->payment_id,
                ] <=> [
                    $b->payment?->paid_at?->timestamp ?? 0,
                    $b->payment_id,
                ]);

            foreach ($inOrder as $allocation) {
                $amount = round((float) $allocation->amount, 2);

                if ($amount <= $room + 0.01) {
                    $room = round($room - $amount, 2);

                    continue;
                }

                /** @var Payment $payment */
                $payment = $allocation->payment;
                $owedTotal += $amount;

                if ($apply) {
                    DB::transaction(function () use ($allocation, $payment, $bill, $amount, $room) {
                        $allocation->forceFill(['applies_to_bill' => false])->save();

                        // Same annotation shape settle() writes live, marked
                        // as a backfill so the worklist reads honestly.
                        $metadata = $payment->metadata ?? [];
                        $metadata['overpayment'] = [
                            'bills' => [
                                ($metadata['overpayment']['bills'][$bill->ulid] ?? []) + [
                                    'allocation_amount' => $amount,
                                    'headroom' => $room,
                                ],
                            ],
                            'detected_at' => now()->toIso8601String(),
                            'backfilled_by' => 'payments:reconcile-overpayments',
                        ];
                        $payment->forceFill(['metadata' => $metadata])->save();

                        app(\App\Services\Billing\PaymentAllocator::class)->recompute($bill);
                    });
                }

                $this->line(($apply ? 'FLAG   ' : 'PRATINJAU ').$bill->bill_number.' <- '.$payment->payment_number.' Rp '.number_format($amount, 0, ',', '.').' (refund terutang)');
                $room = 0.0;
            }
        }

        if ($apply) {
            Log::critical('[ReconcileOverpayments] Backfilled historical double bookings as overpayments', [
                'bills' => $bills->count(), 'owed_total' => $owedTotal,
            ]);
        }

        $this->info(($apply ? 'Diperbaiki: ' : 'Pratinjau: ').$bills->count().' tagihan dobel, total refund terutang Rp '.number_format($owedTotal, 0, ',', '.').($apply ? '' : ' (jalankan dengan --apply untuk menulis)'));

        return self::SUCCESS;
    }
}
