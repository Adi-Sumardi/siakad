<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Billing\PaidBankRecorder;
use Illuminate\Console\Command;

/**
 * Fills payments.paid_bank / paid_va / paid_reference where settlement could
 * not (e-SPP slow to answer, or paid before the columns existed). Hourly it
 * looks at the last 30 days; --all backfills everything.
 */
class RecordPaidBank extends Command
{
    protected $signature = 'payments:record-paid-bank {--all : Every paid payment, not just the last 30 days}';

    protected $description = 'Catat bank, nomor VA dan referensi bank untuk pembayaran VA yang sudah lunas';

    public function handle(PaidBankRecorder $recorder): int
    {
        $payments = Payment::where('status', 'completed')
            ->where('method', 'virtual_account')
            ->where(fn ($q) => $q->whereNull('paid_bank')->orWhereNull('paid_reference'))
            ->when(! $this->option('all'), fn ($q) => $q->where('paid_at', '>=', now()->subDays(30)))
            ->get();

        foreach ($payments as $payment) {
            $recorder->record($payment);
        }

        $done = $payments->filter(fn (Payment $p) => $p->fresh()->paid_reference)->count();
        $this->info("{$payments->count()} pembayaran diperiksa, {$done} kini punya referensi bank.");

        return self::SUCCESS;
    }
}
