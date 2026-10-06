<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\InstallmentSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installment plans for bills whose fee type allows partial payment (audit
 * 6 Okt 2026 #6). Until now `allow_installment` only unlocked a free-form
 * amount at checkout - there was no plan, no per-installment date, and the
 * bill still went overdue on its original date after the first month.
 *
 * A plan splits the remaining balance into N rows. Payment never touches
 * those rows: what is paid is read from the bill (paid_amount minus the
 * baseline recorded at plan time) and walked across them in order, so the
 * money path stays exactly as it was. The bill's own due date moves to the
 * final installment - overdue and late fees follow the plan, not the
 * original single date.
 */
class InstallmentPlanService
{
    public const MIN_COUNT = 2;

    public const MAX_COUNT = 12;

    /** Each installment at least this much, same floor as a custom checkout amount. */
    public const MIN_AMOUNT = 10000;

    public function createPlan(Bill $bill, int $count, Carbon $firstDue, User $by): Bill
    {
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            throw new RuntimeException('Jumlah cicilan harus '.self::MIN_COUNT.'–'.self::MAX_COUNT.' kali.');
        }

        return DB::transaction(function () use ($bill, $count, $firstDue, $by) {
            $bill = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if (! $bill->allow_installment) {
                throw new RuntimeException('Jenis biaya tagihan ini tidak mengizinkan cicilan.');
            }

            if (! $bill->isOpen()) {
                throw new RuntimeException('Hanya tagihan yang belum lunas yang bisa dicicil.');
            }

            if ($firstDue->lt(Carbon::now('Asia/Jakarta')->startOfDay())) {
                throw new RuntimeException('Jatuh tempo cicilan pertama tidak boleh di masa lalu.');
            }

            $remaining = (int) round((float) $bill->remaining_amount);

            if ($remaining < $count * self::MIN_AMOUNT) {
                throw new RuntimeException('Sisa tagihan terlalu kecil untuk dibagi '.$count.' kali (minimal Rp '.number_format(self::MIN_AMOUNT, 0, ',', '.').' per cicilan).');
            }

            // Even split rounded down to the thousand; the last row takes
            // the remainder so the rows always sum to the balance exactly.
            $each = intdiv(intdiv($remaining, $count), 1000) * 1000;
            $oldDue = $bill->due_date?->copy();

            $bill->installments()->delete();

            for ($i = 1; $i <= $count; $i++) {
                InstallmentSchedule::create([
                    'bill_id' => $bill->id,
                    'sequence' => $i,
                    'amount' => $i === $count ? $remaining - $each * ($count - 1) : $each,
                    'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                    'status' => 'unpaid',
                ]);
            }

            $lastDue = $firstDue->copy()->addMonthsNoOverflow($count - 1)->startOfDay();
            $graceDays = $bill->grace_period_end && $oldDue
                ? (int) $oldDue->startOfDay()->diffInDays($bill->grace_period_end->startOfDay(), false)
                : null;

            $bill->forceFill([
                'installment_baseline' => (float) $bill->paid_amount,
                'due_date' => $lastDue->toDateString(),
                'grace_period_end' => $graceDays !== null ? $lastDue->copy()->addDays($graceDays)->toDateString() : null,
                // A bill overdue on its old single date is back on schedule.
                'status' => $bill->status === 'overdue'
                    ? ((float) $bill->paid_amount > 0 ? 'partial' : 'unpaid')
                    : $bill->status,
            ])->save();

            ActivityLog::record($by, 'bill.installment_plan_set', $bill, [
                'bill_number' => $bill->bill_number,
                'count' => $count,
                'first_due' => $firstDue->toDateString(),
                'old_due' => $oldDue?->toDateString(),
                'amount' => $remaining,
            ]);

            return $bill->fresh('installments');
        });
    }

    public function removePlan(Bill $bill, User $by): Bill
    {
        return DB::transaction(function () use ($bill, $by) {
            $bill = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $first = $bill->installments()->orderBy('sequence')->first();

            if (! $first) {
                throw new RuntimeException('Tagihan ini tidak punya rencana cicilan.');
            }

            $bill->installments()->delete();
            $bill->forceFill(['installment_baseline' => null])->save();

            // The due date stays at the plan's final date: pulling it back
            // would flip a family who followed the plan into overdue overnight.
            ActivityLog::record($by, 'bill.installment_plan_removed', $bill, ['bill_number' => $bill->bill_number]);

            return $bill->fresh('installments');
        });
    }

    /**
     * Per-row progress, derived from what the bill has been paid.
     *
     * @return list<array{ulid:string, sequence:int, amount:float, due_date:string, paid:float, status:string}>
     */
    public static function progress(Bill $bill): array
    {
        $rows = $bill->relationLoaded('installments') ? $bill->installments : $bill->installments()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $pool = max(0.0, (float) $bill->paid_amount - (float) $bill->installment_baseline);
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $settled = in_array($bill->status, ['paid', 'waived'], true);

        return $rows->sortBy('sequence')->values()->map(function (InstallmentSchedule $row) use (&$pool, $today, $settled) {
            $amount = (float) $row->amount;
            $paid = $settled ? $amount : min($amount, $pool);
            $pool = max(0.0, $pool - $amount);

            return [
                'ulid' => $row->ulid,
                'sequence' => $row->sequence,
                'amount' => $amount,
                'due_date' => $row->due_date->toDateString(),
                'paid' => round($paid, 2),
                'status' => $paid >= $amount - 0.5
                    ? 'paid'
                    : ($row->due_date->toDateString() < $today ? 'overdue' : ($paid > 0 ? 'partial' : 'unpaid')),
            ];
        })->all();
    }

    /** What the family should pay now to be on schedule: every row due by today, minus what is already in. */
    public static function dueNow(Bill $bill): float
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $rows = self::progress($bill);

        if ($rows === []) {
            return 0.0;
        }

        $due = collect($rows)->filter(fn ($r) => $r['due_date'] <= $today)->sum(fn ($r) => $r['amount'] - $r['paid']);
        $next = collect($rows)->first(fn ($r) => $r['status'] !== 'paid');

        // Nothing due yet: the next upcoming installment is the useful number.
        return round($due > 0 ? $due : ($next ? $next['amount'] - $next['paid'] : 0.0), 2);
    }
}
