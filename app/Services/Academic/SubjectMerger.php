<?php

namespace App\Services\Academic;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folds subjects that were created once per grade level (IND7, IND8, IND9 -
 * all "Bahasa Indonesia") into one subject with several tingkat.
 *
 * - Grouping: same school_unit_id and the same name, trimmed and
 *   case-insensitive. Already-merged rows are skipped, so a second run is a
 *   no-op (idempotent).
 * - Survivor: the active one, then the most-scheduled, then the oldest.
 * - Tingkat: read from the classrooms each subject is scheduled in; a
 *   subject with no schedule falls back to the trailing number of its code.
 * - Every class_schedules / grades row pointing at a duplicate is re-pointed
 *   at the survivor; the duplicate is deactivated and marked merged_into_id,
 *   never deleted. Every write is logged in subject_merge_log so rollback()
 *   restores the exact previous state.
 * - A group where re-pointing grades would collide with the grades unique key
 *   (same student/term/category on two duplicates) is skipped and reported.
 */
class SubjectMerger
{
    /**
     * What apply() would do, without writing anything.
     *
     * @return list<array{school_unit_id: ?int, name: string, survivor: array, duplicates: list<array>, tingkat: list<int>, schedules: int, grades: int, skipped: ?string}>
     */
    public function plan(): array
    {
        $subjects = DB::table('subjects')->whereNull('merged_into_id')->orderBy('id')->get();

        $scheduleCounts = DB::table('class_schedules')
            ->select('subject_id', DB::raw('count(*) as n'))
            ->groupBy('subject_id')
            ->pluck('n', 'subject_id');

        return $subjects
            ->groupBy(fn ($s) => ($s->school_unit_id ?? 'global').'|'.mb_strtolower(trim($s->name)))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(function (Collection $group) use ($scheduleCounts) {
                $sorted = $group->sortBy([
                    fn ($a, $b) => (int) $b->is_active <=> (int) $a->is_active,
                    fn ($a, $b) => ($scheduleCounts[$b->id] ?? 0) <=> ($scheduleCounts[$a->id] ?? 0),
                    fn ($a, $b) => $a->id <=> $b->id,
                ])->values();

                $survivor = $sorted->first();
                $duplicates = $sorted->slice(1)->values();
                $dupIds = $duplicates->pluck('id')->all();

                return [
                    'school_unit_id' => $survivor->school_unit_id,
                    'name' => $survivor->name,
                    'survivor' => ['id' => $survivor->id, 'code' => $survivor->code],
                    'duplicates' => $duplicates->map(fn ($d) => ['id' => $d->id, 'code' => $d->code])->all(),
                    'tingkat' => $group->flatMap(fn ($s) => $this->tingkatOf($s))->unique()->sort()->values()->all(),
                    'schedules' => DB::table('class_schedules')->whereIn('subject_id', $dupIds)->count(),
                    'grades' => DB::table('grades')->whereIn('subject_id', $dupIds)->count(),
                    'skipped' => $this->gradeCollision($group->pluck('id')->all())
                        ? 'Ada siswa dengan nilai di lebih dari satu mapel duplikat pada semester dan kategori yang sama.'
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Runs the merge and fills subject_tingkat for every subject that has none yet. Returns the plan it executed. */
    public function apply(): array
    {
        $plan = $this->plan();

        DB::transaction(function () use ($plan) {
            foreach ($plan as $group) {
                if ($group['skipped']) {
                    continue;
                }
                $this->mergeGroup($group);
            }

            $this->backfillTingkat();
        });

        return $plan;
    }

    /** Undoes every logged merge, newest first. */
    public function rollback(): void
    {
        DB::transaction(function () {
            DB::table('subject_merge_log')->orderByDesc('id')->get()->each(function ($log) {
                $previous = json_decode((string) $log->previous, true) ?? [];

                match ($log->table_name) {
                    'subjects' => DB::table('subjects')->where('id', $log->row_id)->update([
                        'is_active' => $previous['is_active'] ?? true,
                        'merged_into_id' => null,
                    ]),
                    'subject_tingkat' => DB::table('subject_tingkat')->where('id', $log->row_id)->delete(),
                    default => DB::table($log->table_name)->where('id', $log->row_id)->update(['subject_id' => $log->subject_id]),
                };
            });

            DB::table('subject_merge_log')->delete();
        });
    }

    private function mergeGroup(array $group): void
    {
        $intoId = $group['survivor']['id'];

        foreach ($group['duplicates'] as $dup) {
            $fromId = $dup['id'];

            foreach (['class_schedules', 'grades'] as $table) {
                $ids = DB::table($table)->where('subject_id', $fromId)->pluck('id');
                foreach ($ids as $rowId) {
                    $this->log($fromId, $intoId, $table, $rowId);
                }
                DB::table($table)->whereIn('id', $ids)->update(['subject_id' => $intoId]);
            }

            $row = DB::table('subjects')->where('id', $fromId)->first();
            $this->log($fromId, $intoId, 'subjects', $fromId, ['is_active' => (bool) $row->is_active]);
            DB::table('subjects')->where('id', $fromId)->update([
                'is_active' => false,
                'merged_into_id' => $intoId,
                'updated_at' => now(),
            ]);
        }

        foreach ($group['tingkat'] as $tingkat) {
            $this->addTingkat($intoId, $tingkat, $group['duplicates'][0]['id']);
        }
    }

    /** Subjects that never had a tingkat recorded get one from their schedules or code; none found = stays "all tingkat". */
    private function backfillTingkat(): void
    {
        DB::table('subjects')
            ->whereNull('merged_into_id')
            ->whereNotExists(fn ($q) => $q->from('subject_tingkat')->whereColumn('subject_tingkat.subject_id', 'subjects.id'))
            ->get()
            ->each(function ($subject) {
                foreach ($this->tingkatOf($subject) as $tingkat) {
                    $this->addTingkat($subject->id, $tingkat, $subject->id);
                }
            });
    }

    private function addTingkat(int $subjectId, int $tingkat, int $logFromId): void
    {
        if (DB::table('subject_tingkat')->where('subject_id', $subjectId)->where('tingkat', $tingkat)->exists()) {
            return;
        }

        $id = DB::table('subject_tingkat')->insertGetId([
            'subject_id' => $subjectId, 'tingkat' => $tingkat, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->log($logFromId, $subjectId, 'subject_tingkat', $id);
    }

    /** @return list<int> */
    private function tingkatOf(object $subject): array
    {
        $fromSchedules = DB::table('class_schedules')
            ->join('classrooms', 'classrooms.id', '=', 'class_schedules.classroom_id')
            ->where('class_schedules.subject_id', $subject->id)
            ->distinct()
            ->pluck('classrooms.tingkat')
            ->map(fn ($t) => (int) $t)
            ->all();

        if ($fromSchedules) {
            return $fromSchedules;
        }

        if ($subject->code && preg_match('/(\d{1,2})$/', $subject->code, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return [(int) $m[1]];
        }

        return [];
    }

    private function gradeCollision(array $subjectIds): bool
    {
        return DB::table('grades')
            ->whereIn('subject_id', $subjectIds)
            ->select('student_id', 'term_id', 'category')
            ->groupBy('student_id', 'term_id', 'category')
            ->havingRaw('count(*) > 1')
            ->exists();
    }

    private function log(int $fromId, int $intoId, string $table, int $rowId, ?array $previous = null): void
    {
        DB::table('subject_merge_log')->insert([
            'subject_id' => $fromId,
            'merged_into_id' => $intoId,
            'table_name' => $table,
            'row_id' => $rowId,
            'previous' => $previous ? json_encode($previous) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
