<?php

namespace App\Services\Reporting;

use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Billing\PaidBankRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Laporan > Recon (2026-10-07, same report as PMB's Transaksi > Recon):
 * money that came in over a period of PAYMENT dates, totalled per unit by
 * fee type and by the bank it reached, then listed per unit with the bank's
 * own reference, to tick off against the BSI and Muamalat statements.
 *
 * Built from allocations, not payments: one transfer can settle a brother's
 * SD bill and his sister's SMP bill, and each unit counts only its own
 * share. Such a transfer shows as two lines with the same No. Pembayaran.
 * Shared by the PDF and the Excel, so the two can never disagree.
 */
class CollectionReconService
{
    /**
     * @param  array{from: string, to: string, bank?: ?string, fee_type?: ?string, unit?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters, User $user): array
    {
        // Siakad stores times in its own app timezone (Asia/Jakarta since
        // 2026-09-23), not UTC as PMB does - the range is used as is.
        $tz = config('app.timezone');
        $from = CarbonImmutable::parse($filters['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'], $tz)->endOfDay();
        $bank = in_array($filters['bank'] ?? null, ['muamalat', 'bsi'], true) ? $filters['bank'] : null;
        $feeType = ($filters['fee_type'] ?? 'all') !== 'all' ? $filters['fee_type'] : null;
        $unit = ($filters['unit'] ?? 'all') !== 'all' ? $filters['unit'] : null;

        $allocations = PaymentAllocation::query()
            ->with(['payment', 'bill.feeType', 'bill.student.schoolUnit'])
            ->whereHas('payment', fn ($q) => $q->where('status', 'completed')
                ->whereBetween('paid_at', [$from, $to])
                ->when($bank, fn ($q) => $q->where('paid_bank', $bank)))
            // Per bill, not per payment: a unit admin sees only their own
            // unit's share of a transfer that also paid another unit's bill.
            ->whereHas('bill.student', fn ($q) => $q->visibleTo($user))
            ->when($feeType, fn ($q) => $q->whereHas('bill.feeType', fn ($f) => $f->where('code', $feeType)))
            ->when($unit, fn ($q) => $q->whereHas('bill.student.schoolUnit', fn ($u) => $u->where('code', $unit)))
            ->get();

        $rows = $allocations
            ->sortBy(fn (PaymentAllocation $a) => $a->payment->paid_at?->getTimestamp())
            ->map(fn (PaymentAllocation $a) => $this->row($a))
            ->values();

        $types = $allocations
            ->map(fn (PaymentAllocation $a) => $a->bill?->feeType)
            ->filter()
            ->unique('id')
            ->sortBy(fn ($t) => [$t->sort_order ?? 0, $t->name])
            ->mapWithKeys(fn ($t) => [$t->code => $t->name]);

        $units = $rows->groupBy('unit_key')
            ->sortBy(fn ($unitRows) => [$unitRows->first()['unit_sort'], $unitRows->first()['unit']])
            ->map(fn ($unitRows) => [
                'unit' => $unitRows->first()['unit'],
                'rows' => $unitRows->values()->all(),
            ] + $this->totals($unitRows, $types->keys()))
            ->values();

        return [
            'period_label' => $this->periodLabel($from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filters' => [
                'Bank' => $bank ? PaidBankRecorder::LABELS[$bank] : 'Semua bank',
                'Jenis biaya' => $feeType ? ($types[$feeType] ?? $feeType) : 'Semua jenis biaya',
                'Unit' => $unit ? ($units->first()['unit'] ?? $unit) : 'Semua unit',
            ],
            'fee_types' => $types->all(),
            'units' => $units->all(),
            'grand' => $this->totals($rows, $types->keys()),
            'printed_at' => CarbonImmutable::now()->locale('id')->translatedFormat('j F Y, H:i').' WIB',
            'printed_by' => $user->name,
        ];
    }

    public function filename(array $report, string $extension): string
    {
        return "Recon-Penerimaan-{$report['from']}_{$report['to']}.{$extension}";
    }

    /** @return array<string, mixed> */
    private function row(PaymentAllocation $a): array
    {
        $payment = $a->payment;
        $bill = $a->bill;
        $student = $bill?->student;
        $paidAt = $payment->paid_at ? CarbonImmutable::parse($payment->paid_at) : null;
        $bank = $payment->paid_bank;

        return [
            'paid_at' => $paidAt?->format('d/m/Y H:i') ?? '-',
            'paid_date' => $paidAt?->toDateString(),
            'nis' => $student?->nis ?: ($student?->no_pendaftaran ?: '-'),
            'nama' => $student?->nama_lengkap ?? '-',
            'unit' => $student?->schoolUnit?->label ?? 'Tanpa unit',
            'unit_key' => (string) ($student?->school_unit_id ?? 0),
            'unit_sort' => $student?->schoolUnit?->sort_order ?? 999,
            'fee_type' => $bill?->feeType?->code ?? 'lainnya',
            'tagihan' => $bill?->description ?: ($bill?->feeType?->name ?? '-'),
            'bank' => $bank,
            'bank_label' => $bank
                ? PaidBankRecorder::LABELS[$bank]
                : ($payment->method === 'virtual_account' ? 'Belum diketahui' : 'Lainnya'),
            'va_number' => $payment->paid_va ?? '-',
            'reference' => $payment->paid_reference ?? '-',
            'payment_number' => $payment->payment_number ?? '-',
            'amount' => (float) $a->amount,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, string>  $types
     * @return array<string, mixed>
     */
    private function totals(Collection $rows, Collection $types): array
    {
        $sum = fn ($subset) => ['count' => $subset->count(), 'amount' => (float) $subset->sum('amount')];

        return [
            'by_type' => $types->mapWithKeys(fn ($t) => [$t => $sum($rows->where('fee_type', $t))])->all(),
            'by_bank' => [
                'muamalat' => $sum($rows->where('bank', 'muamalat')),
                'bsi' => $sum($rows->where('bank', 'bsi')),
                // Not a VA, or a VA whose bank is not known yet - shown so a
                // gap is visible, not hidden inside the total.
                'other' => $sum($rows->whereNull('bank')),
            ],
            'count' => $rows->count(),
            'amount' => (float) $rows->sum('amount'),
        ];
    }

    private function periodLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $fmt = fn (CarbonImmutable $d) => $d->locale('id')->translatedFormat('j F Y');

        return $from->isSameDay($to) ? $fmt($from) : $fmt($from).' - '.$fmt($to);
    }
}
