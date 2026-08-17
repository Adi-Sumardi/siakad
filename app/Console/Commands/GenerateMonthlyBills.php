<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Services\Billing\BillGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Issues the month's recurring bills, normally SPP.
 *
 * Safe to run twice: every bill carries a dedup_key unique per student, so a
 * scheduler that fires again - or an admin who reruns it - adds nothing.
 */
class GenerateMonthlyBills extends Command
{
    protected $signature = 'bills:generate
                            {--type=spp : Kode jenis biaya}
                            {--month= : Bulan 1-12, default bulan berjalan}
                            {--unit= : Kode unit, default semua unit}
                            {--preview : Tampilkan hasilnya tanpa menerbitkan apa pun}';

    protected $description = 'Terbitkan tagihan bulanan untuk siswa aktif';

    public function handle(BillGenerator $generator): int
    {
        $type = FeeType::where('code', $this->option('type'))->first();

        if (! $type) {
            $this->error("Jenis biaya '{$this->option('type')}' tidak ada.");

            return self::FAILURE;
        }

        $year = AcademicYear::current();

        if (! $year) {
            $this->error('Belum ada tahun ajaran aktif.');

            return self::FAILURE;
        }

        $month = (int) ($this->option('month') ?: Carbon::now()->month);
        $unit = $this->option('unit') ? SchoolUnit::findByCode($this->option('unit')) : null;

        if ($this->option('unit') && ! $unit) {
            $this->error("Unit '{$this->option('unit')}' tidak ada.");

            return self::FAILURE;
        }

        if ($this->option('preview')) {
            $preview = $generator->preview($type, $year, $unit, $month);

            $this->info("{$preview['eligible']} tagihan akan terbit, total Rp ".number_format($preview['total_amount'], 0, ',', '.'));
            $this->line('Potongan: Rp '.number_format($preview['discount_amount'], 0, ',', '.'));

            foreach ($preview['skipped'] as $row) {
                $this->warn("  dilewati: {$row['student']} — {$row['reason']} ({$row['detail']})");
            }

            return self::SUCCESS;
        }

        $run = $generator->run($type, $year, $unit, $month, $year->activeTerm());

        $this->info("{$run->bills_created} tagihan terbit, {$run->bills_skipped} dilewati, total Rp "
            .number_format((float) $run->total_amount, 0, ',', '.'));

        return self::SUCCESS;
    }
}
