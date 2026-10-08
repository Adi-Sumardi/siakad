<?php

namespace App\Console\Commands;

use App\Services\Academic\SubjectMerger;
use Illuminate\Console\Command;

/**
 * Dry-run by default: lists which duplicate subjects (same unit, same name)
 * would be folded together. --apply runs it, --rollback undoes every logged
 * merge. The 2026_10_08 migration already applies it once.
 */
class MergeDuplicateSubjects extends Command
{
    protected $signature = 'subjects:merge-duplicates {--apply : Jalankan penggabungan} {--rollback : Batalkan semua penggabungan yang tercatat}';

    protected $description = 'Gabungkan mapel duplikat per tingkat (mis. IND7/IND8/IND9) menjadi satu mapel dengan beberapa tingkat';

    public function handle(SubjectMerger $merger): int
    {
        if ($this->option('rollback')) {
            $merger->rollback();
            $this->info('Penggabungan mapel dibatalkan.');

            return self::SUCCESS;
        }

        $plan = $this->option('apply') ? $merger->apply() : $merger->plan();

        if ($plan === []) {
            $this->info('Tidak ada mapel duplikat.');

            return self::SUCCESS;
        }

        $this->table(
            ['Unit', 'Mapel', 'Dipertahankan', 'Digabung', 'Tingkat', 'Jadwal', 'Nilai', 'Status'],
            collect($plan)->map(fn ($g) => [
                $g['school_unit_id'] ?? 'global',
                $g['name'],
                "#{$g['survivor']['id']} {$g['survivor']['code']}",
                collect($g['duplicates'])->map(fn ($d) => "#{$d['id']} {$d['code']}")->implode(', '),
                implode(', ', $g['tingkat']),
                $g['schedules'],
                $g['grades'],
                $g['skipped'] ? 'DILEWATI: '.$g['skipped'] : ($this->option('apply') ? 'digabung' : 'akan digabung'),
            ]),
        );

        if (! $this->option('apply')) {
            $this->comment('Dry-run: belum ada yang diubah. Jalankan dengan --apply untuk menggabungkan.');
        }

        return self::SUCCESS;
    }
}
