<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\Term;
use App\Services\Points\PointThresholdNotifier;
use Illuminate\Console\Command;

/**
 * The daily sweep. Runs after school starts, not at midnight, so a family
 * reads it during a normal day rather than as the first thing on their phone
 * before dawn.
 *
 * Idempotent by construction, same as the bill reminder run: the unique row in
 * point_threshold_notifications is what stops a second firing from sending
 * anything, not a promise that this command only runs once.
 */
class EvaluatePointThresholds extends Command
{
    protected $signature = 'points:evaluate-thresholds
                            {--dry-run : Tampilkan siapa yang akan diberi tahu, tanpa mengirim}';

    protected $description = 'Kirim notifikasi wali murid saat saldo poin melewati ambang';

    public function handle(PointThresholdNotifier $notifier): int
    {
        $term = Term::current();

        if (! $term) {
            $this->error('Belum ada semester aktif.');

            return self::FAILURE;
        }

        $students = Student::active()->with('guardians')->get();
        $sent = 0;

        foreach ($students as $student) {
            if ($this->option('dry-run')) {
                continue;
            }

            if ($notifier->evaluate($student, $term)) {
                $sent++;
            }
        }

        $this->option('dry-run')
            ? $this->info("Dry run: {$students->count()} siswa dievaluasi, tidak ada yang dikirim.")
            : $this->info("{$sent} notifikasi ambang poin terkirim dari {$students->count()} siswa dievaluasi.");

        return self::SUCCESS;
    }
}
