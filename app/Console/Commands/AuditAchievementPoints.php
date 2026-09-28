<?php

namespace App\Console\Commands;

use App\Models\Achievement;
use App\Models\PointRecord;
use App\Models\Student;
use Illuminate\Console\Command;

/**
 * The read-only half of the achievement-points audit (bug batch Poin 6C).
 *
 * The repeated-verification bug (era before the atomic claim) could write
 * more than one merit row for the same achievement; its mirror image -
 * verifying without explicit points - could leave a decided row carrying
 * point_awarded with no ledger row behind it. This command FINDS both, and
 * the per-student gap between what the achievements say was awarded and
 * what the ledger actually records.
 *
 * It never writes. The repair is a separate, explicit step
 * (prestasi:repair-points --confirm) taken only after a human has read
 * this output - the school's data, the school's call.
 */
class AuditAchievementPoints extends Command
{
    protected $signature = 'prestasi:audit-points';

    protected $description = 'Audit poin prestasi: duplikat merit, poin yang tidak pernah tercatat, dan selisih per siswa (read-only)';

    public function handle(): int
    {
        $dupes = $this->duplicateMerits();
        $missing = $this->missingMeritRows();
        $orphans = $this->orphanMerits();

        $this->components->twoColumnDetail('Prestasi dengan >1 merit aktif', (string) $dupes->count());
        $this->components->twoColumnDetail('Prestasi terverifikasi yang poinnya tidak pernah tercatat', (string) $missing->count());
        $this->components->twoColumnDetail('Merit aktif untuk prestasi yang tidak terverifikasi', (string) $orphans->count());

        if ($dupes->isNotEmpty()) {
            $this->newLine();
            $this->warn('DUPLIKAT MERIT (akibat verifikasi berulang - akar bug Poin 6):');
            $this->table(
                ['Prestasi', 'Siswa', 'Baris merit aktif', 'Total tercatat', 'Seharusnya (1x)'],
                $dupes->map(fn (object $d) => [
                    $d->nama_prestasi.' ('.$d->ulid.')',
                    $d->siswa ?? '-',
                    (string) $d->rows,
                    (string) $d->total,
                    (string) $d->point_awarded,
                ])->all(),
            );
        }

        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->warn('POIN TIDAK PERNAH TERCATAT (verifikasi tanpa poin eksplisit):');
            $this->table(
                ['Prestasi', 'Siswa', 'point_awarded', 'Baris merit aktif'],
                $missing->map(fn (Achievement $a) => [
                    $a->nama_prestasi.' ('.$a->ulid.')',
                    $a->student?->nama_lengkap ?? '-',
                    (string) $a->point_awarded,
                    '0',
                ])->all(),
            );
            $this->line('  → Tidak diperbaiki otomatis: butuh keputusan (poin masuk semester mana). Verifikasi ulang tidak mungkin setelah 409/uniqueness - tambahkan manual bila disepakati.');
        }

        if ($orphans->isNotEmpty()) {
            $this->newLine();
            $this->warn('MERIT YATIM (prestasi belum/tidak terverifikasi tapi poinnya sudah masuk):');
            $this->table(
                ['Prestasi', 'Status', 'Siswa', 'Poin aktif'],
                $orphans->map(fn (object $o) => [
                    $o->nama_prestasi.' ('.$o->ulid.')',
                    $o->status,
                    $o->siswa ?? '-',
                    (string) $o->total,
                ])->all(),
            );
            $this->line('  → Tidak diperbaiki otomatis: tinjau kasus per kasus.');
        }

        $affectedStudentIds = $dupes->pluck('student_id')
            ->merge($missing->pluck('student_id'))
            ->merge($orphans->pluck('student_id'))
            ->filter()
            ->unique()
            ->values();

        if ($affectedStudentIds->isNotEmpty()) {
            $this->newLine();
            $this->warn('SELISIH PER SISWA TERDAMPAK (kolom "Selisih" = tercatat - seharusnya; positif berarti kelebihan poin):');
            $rows = [];
            foreach ($affectedStudentIds as $studentId) {
                [$expected, $recorded] = $this->expectedVersusRecorded($studentId);
                $student = Student::find($studentId);
                $rows[] = [
                    $student?->nama_lengkap ?? "siswa #{$studentId}",
                    (string) $expected,
                    (string) $recorded,
                    (string) ($recorded - $expected),
                ];
            }
            $this->table(['Siswa', 'Seharusnya', 'Tercatat', 'Selisih'], $rows);
        }

        $this->newLine();
        if ($dupes->isEmpty() && $missing->isEmpty() && $orphans->isEmpty()) {
            $this->info('Bersih: tidak ada anomali poin prestasi.');
        } else {
            $this->info('Duplikat merit bisa diperbaiki dengan `php artisan prestasi:repair-points --confirm` (yang lain: manual, setelah review).');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function duplicateMerits(): \Illuminate\Support\Collection
    {
        return PointRecord::query()
            ->active()
            ->whereNotNull('related_achievement_id')
            ->selectRaw('related_achievement_id, COUNT(*) AS rows, SUM(points) AS total')
            ->groupBy('related_achievement_id')
            ->havingRaw('rows > 1')
            ->get()
            ->map(function (object $row) {
                $achievement = Achievement::with('student')->find($row->related_achievement_id);

                return (object) [
                    'ulid' => $achievement?->ulid ?? '#'.$row->related_achievement_id,
                    'nama_prestasi' => $achievement?->nama_prestasi ?? '(prestasi terhapus)',
                    'siswa' => $achievement?->student?->nama_lengkap,
                    'student_id' => $achievement?->student_id,
                    'rows' => (int) $row->rows,
                    'total' => (int) $row->total,
                    'point_awarded' => (int) ($achievement?->point_awarded ?? 0),
                ];
            });
    }

    /** Decided-with-points rows the ledger never heard about. @return \Illuminate\Support\Collection<int, Achievement> */
    private function missingMeritRows(): \Illuminate\Support\Collection
    {
        return Achievement::query()
            ->where('status', 'verified')
            ->whereNotNull('point_awarded')
            ->whereDoesntHave('pointRecords', fn ($q) => $q->active())
            ->with('student')
            ->get();
    }

    /** Active merit rows tied to an achievement that never reached 'verified'. @return \Illuminate\Support\Collection<int, object> */
    private function orphanMerits(): \Illuminate\Support\Collection
    {
        // Plain qualified joins, not Eloquent scopes: point_records and
        // achievements BOTH carry a `status` column, so an unqualified
        // scope would be ambiguous once the join lands.
        return PointRecord::query()
            ->join('achievements', 'achievements.id', '=', 'point_records.related_achievement_id')
            ->join('students', 'students.id', '=', 'achievements.student_id')
            ->where('point_records.status', 'recorded')
            ->whereNot('achievements.status', 'verified')
            ->groupBy('achievements.id', 'achievements.ulid', 'achievements.nama_prestasi', 'achievements.status', 'achievements.student_id', 'students.nama_lengkap')
            ->selectRaw('achievements.ulid AS ulid, achievements.nama_prestasi AS nama_prestasi, achievements.status AS status, achievements.student_id AS student_id, students.nama_lengkap AS siswa, SUM(point_records.points) AS total')
            ->get();
    }

    /** @return array{0: int, 1: int} expected (verified point_awarded sum) vs recorded (active achievement merits) */
    private function expectedVersusRecorded(int $studentId): array
    {
        $expected = (int) Achievement::query()
            ->where('student_id', $studentId)
            ->where('status', 'verified')
            ->whereNotNull('point_awarded')
            ->sum('point_awarded');

        $recorded = (int) PointRecord::query()
            ->active()
            ->where('student_id', $studentId)
            ->whereNotNull('related_achievement_id')
            ->sum('points');

        return [$expected, $recorded];
    }
}
