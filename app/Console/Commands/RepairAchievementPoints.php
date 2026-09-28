<?php

namespace App\Console\Commands;

use App\Models\PointRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The repair half of the achievement-points audit (bug batch Poin 6C).
 *
 * Scope is deliberately narrow: it only REVOKES the later duplicates among
 * a prestasi's active merit rows (keeping the earliest), because that is
 * the one anomaly with an unambiguous fix. "Missing" and "orphan" cases
 * stay manual - they need a human decision (which term, whether at all),
 * and prestasi:audit-points prints them for exactly that conversation.
 *
 * A revoke, never a DELETE (docs/01-ARSITEKTUR.md D6): the extra rows stay
 * in the ledger, excluded from every balance but still explaining what the
 * bug did and when. Default is a dry run; nothing is written without
 * --confirm, after the audit output has been reviewed.
 */
class RepairAchievementPoints extends Command
{
    protected $signature = 'prestasi:repair-points
                            {--confirm : Benar-benar revoke duplikatnya (tanpa flag ini: dry run)}';

    protected $description = 'Batalkan (revoke) duplikat merit prestasi yang lahir dari bug verifikasi berulang';

    public function handle(): int
    {
        $duplicateGroups = PointRecord::query()
            ->active()
            ->whereNotNull('related_achievement_id')
            ->selectRaw('related_achievement_id, COUNT(*) AS total')
            ->groupBy('related_achievement_id')
            ->havingRaw('total > 1')
            ->pluck('related_achievement_id');

        if ($duplicateGroups->isEmpty()) {
            $this->info('Tidak ada duplikat merit aktif - tidak ada yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $toRevoke = PointRecord::query()
            ->active()
            ->whereIn('related_achievement_id', $duplicateGroups)
            ->orderBy('id')
            ->get()
            ->groupBy('related_achievement_id')
            ->flatMap(fn ($rows) => $rows->skip(1)); // keep the earliest, revoke the rest

        $this->table(
            ['Baris merit (ulid)', 'Prestasi #', 'Siswa #', 'Poin', 'Dicatat pada'],
            $toRevoke->map(fn (PointRecord $r) => [
                $r->ulid, '#'.$r->related_achievement_id, '#'.$r->student_id,
                (string) $r->points, $r->created_at?->format('Y-m-d H:i'),
            ])->all(),
        );

        if (! $this->option('confirm')) {
            $this->info(count($toRevoke).' baris duplikat akan di-revoke. Dry run - tidak ada yang diubah; tambahkan --confirm setelah review.');

            return self::SUCCESS;
        }

        // --confirm IS the confirmation (read the dry run first); no extra
        // interactive prompt, so the repair stays scriptable.
        DB::transaction(function () use ($toRevoke) {
            foreach ($toRevoke as $record) {
                $record->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'Perbaikan audit: duplikat poin prestasi akibat bug verifikasi berulang (batch Poin 6)',
                ])->save();
            }
        });

        $this->info(count($toRevoke).' baris duplikat di-revoke. Jalankan `php artisan prestasi:audit-points` untuk melihat sisa anomali.');

        return self::SUCCESS;
    }
}
