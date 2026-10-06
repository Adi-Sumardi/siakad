<?php

namespace App\Services\Academic;

use App\Models\AcademicPolicy;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * The academic watchlist behind the dashboard's "Perlu Perhatian" numbers
 * and the /admin/perhatian drill-down: the union of four conditions, decided
 * per student - alpa rollup on the current-year enrollment, final average
 * below KKM, a large average drop between terms, and point violations this
 * term.
 *
 * One service for both screens so the count on a tile and the list of names
 * behind it can never drift apart - they read these same rows.
 */
class WatchlistService
{
    /** Out-of-the-box KKM; the effective one is AcademicPolicy::forUnit() (audit 6 Okt 2026 #12, T24). */
    public const KKM = 70;

    /** An enrollment's rollup alpa count from which a child is worth flagging. */
    public const HIGH_ABSENTEEISM_ALPA = 5;

    /** A final-grade drop this large (in points) between terms counts as decline. */
    public const GRADE_DROP_POINTS = 5;

    public const REASONS = ['absenteeism', 'below_kkm', 'grade_decline', 'point_violation'];

    /**
     * Every student tripping at least one watchlist condition, with the
     * reasons and the numbers behind them. Students flagged by nothing are
     * simply absent - the drill-down shows conditions, not rosters.
     *
     * @param  Collection<int, \App\Models\Student>  $students  the caller's visibleTo()-scoped students
     * @param  Collection<int, \App\Models\Enrollment>  $enrollments  active enrollments of the current academic year
     * @param  Collection  $grades  grade rows (student_id, subject_id, term_id, category, score) for current + previous term
     * @param  Collection  $points  point rows (student_id, points) already filtered to this term's recorded records
     * @return Collection<int, array{student_id:int,reasons:list<string>,alpa_count:int,current_average:?float,previous_average:?float,average_drop:?float,violation_records:int}> keyed by student id
     */
    public function identify(Collection $students, Collection $enrollments, Collection $grades, Collection $points, ?Term $term, ?Term $prevTerm): Collection
    {
        // Population = ACTIVE students only (audit T63-b): a graduated or
        // transferred student still carries this term's grade/point rows,
        // and without this filter they kept appearing on the "Perlu
        // Perhatian" tile and drill-down while the active-student KPI
        // excluded them - the dashboard arguing with itself.
        $students = $students->where('status', 'active');

        $unitByStudent = $students->pluck('school_unit_id', 'id');
        $current = $this->perStudentAverages($grades, $term?->id, $unitByStudent);
        $previous = $this->perStudentAverages($grades, $prevTerm?->id, $unitByStudent);
        $enrollmentByStudent = $enrollments->groupBy('student_id');
        $violationsByStudent = $points->filter(fn ($p) => (int) $p->points < 0)->groupBy('student_id');

        return $students
            ->map(function ($student) use ($enrollmentByStudent, $violationsByStudent, $current, $previous) {
                $reasons = [];
                // The child's own unit policy (audit 6 Okt 2026 #12).
                $policy = AcademicPolicy::forUnit($student->school_unit_id);

                $alpa = (int) ($enrollmentByStudent->get($student->id)?->first()->absent_count ?? 0);
                if ($alpa >= $policy->alpa_threshold) {
                    $reasons[] = 'absenteeism';
                }

                $cur = $current->get($student->id);
                if ($cur !== null && $cur < $policy->kkm) {
                    $reasons[] = 'below_kkm';
                }

                $prev = $previous->get($student->id);
                $drop = ($cur !== null && $prev !== null) ? round($prev - $cur, 2) : null;
                if ($drop !== null && $drop >= $policy->grade_drop) {
                    $reasons[] = 'grade_decline';
                }

                $violations = $violationsByStudent->get($student->id)?->count() ?? 0;
                if ($violations > 0) {
                    $reasons[] = 'point_violation';
                }

                if ($reasons === []) {
                    return null;
                }

                return [
                    'student_id' => $student->id,
                    'reasons' => $reasons,
                    'alpa_count' => $alpa,
                    'current_average' => $cur,
                    'previous_average' => $prev,
                    'average_drop' => $drop,
                    'violation_records' => $violations,
                ];
            })
            ->filter()
            ->keyBy('student_id');
    }

    /**
     * Per-student average final score for one term.
     *
     * A student sits many subjects, so the score is first finalized per
     * subject (GradeService weighting, null when a subject's categories are
     * incomplete) and then averaged across that student's complete subjects -
     * NOT collapsed across subjects, which would silently keep only the last
     * subject's data per category.
     *
     * @return Collection<int, float> keyed by student id, only students with at least one complete subject
     */
    public function perStudentAverages(Collection $termGrades, ?int $termId, ?Collection $unitByStudent = null): Collection
    {
        if (! $termId) {
            return collect();
        }

        return $termGrades
            ->where('term_id', $termId)
            ->groupBy(fn ($g) => $g->student_id.'|'.$g->subject_id)
            ->map(function (Collection $rows) use ($unitByStudent) {
                $scores = $rows->pluck('score', 'category')->map(fn ($s) => (float) $s);
                $unitId = $unitByStudent?->get($rows->first()->student_id);

                return GradeService::weighted($scores, AcademicPolicy::forUnit($unitId)->weights());
            })
            ->filter(fn ($final) => $final !== null)
            ->groupBy(fn ($final, string $key) => explode('|', $key)[0])
            ->map(fn (Collection $finals) => round($finals->avg(), 2));
    }

    /**
     * The term immediately before this one: the latest term that starts
     * earlier, across year boundaries too. Strictly chronological - the old
     * cross-year branch matched by NAME first ("ganjil vs ganjil a year
     * apart"), which skipped the genap sitting directly in between and made
     * the grade-drop detector compare against a year-old baseline.
     */
    public function previousTerm(?Term $term): ?Term
    {
        if (! $term) {
            return null;
        }

        return Term::query()
            ->where('starts_on', '<', $term->starts_on)
            ->orderByDesc('starts_on')
            ->first();
    }
}
