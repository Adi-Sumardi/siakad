<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\FeeRate;
use App\Services\Billing\PaymentAllocator;
use Illuminate\Console\Command;

/**
 * Charges the flat late fee a fee rate configured, once, after the bill's
 * grace window has fully passed (audit 2026-10-05).
 *
 * The school has been able to configure late_fee_amount/late_fee_grace_days
 * on /admin/tarif and via CSV import since Fase 2 - and nothing ever read
 * them: bills were born late_fee=0 and stayed there, so tunggakan quietly
 * understated the school's own denda policy. This command closes that loop.
 *
 * Idempotent by the late_fee = 0 guard: a bill is charged exactly once, no
 * matter how often the sweep fires. Money-touching like every billing run:
 * preview by default, --apply to write.
 */
class ApplyLateFees extends Command
{
    protected $signature = 'bills:apply-late-fees {--apply : Tulis perubahan (tanpa ini: hanya pratinjau)}';

    protected $description = 'Kenakan denda keterlambatan pada tagihan yang lewat masa tenggang (pratinjau kecuali --apply)';

    public function handle(PaymentAllocator $allocator): int
    {
        $apply = (bool) $this->option('apply');

        $rates = FeeRate::query()
            ->where('late_fee_amount', '>', 0)
            ->get()
            ->keyBy('id');

        // Candidates: still owing something, never charged (late_fee = 0),
        // grace window fully past. A NULL grace_period_end means the rate
        // never carried grace days - the fee is due once the BILL is past
        // due (grace = 0).
        $bills = Bill::query()
            ->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->where('late_fee', 0)
            ->whereNotNull('fee_rate_id')
            ->whereDate('due_date', '<', now()->startOfDay())
            ->where(fn ($q) => $q
                ->whereNull('grace_period_end')
                ->orWhereDate('grace_period_end', '<', now()->startOfDay()))
            ->orderBy('id')
            ->get()
            ->filter(fn (Bill $bill) => (float) ($rates[$bill->fee_rate_id]?->late_fee_amount ?? 0) > 0);

        if ($bills->isEmpty()) {
            $this->info('Tidak ada tagihan yang memenuhi syarat denda.');

            return self::SUCCESS;
        }

        $total = 0.0;

        foreach ($bills as $bill) {
            $fee = round((float) $rates[$bill->fee_rate_id]->late_fee_amount, 2);
            $total += $fee;

            if ($apply) {
                // total_amount grows by the fee; recompute() stays the one
                // writer of remaining_amount and status (an overdue bill
                // that was partly paid may flip between partial/overdue per
                // its usual precedence - the same rule every other payment
                // event applies).
                $bill->forceFill([
                    'late_fee' => $fee,
                    'total_amount' => round((float) $bill->total_amount + $fee, 2),
                ])->save();

                $allocator->recompute($bill->fresh());
            }

            $this->line(($apply ? 'DENDA  ' : 'PRATINJAU ').$bill->bill_number.' +Rp '.number_format($fee, 0, ',', '.'));
        }

        $this->info(($apply ? 'Dikenakan: ' : 'Pratinjau: ').$bills->count().' tagihan, total Rp '.number_format($total, 0, ',', '.').($apply ? '' : ' (jalankan dengan --apply untuk menulis)'));

        return self::SUCCESS;
    }
}
