<?php

namespace App\Services\Academic;

use App\Models\AcademicYear;
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
    /** Assumed school KKM - a dashboard threshold, not a stored policy (yet, see T24). */
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
        $current = $this->perStudentAverages($grades, $term?->id);
        $previous = $this->perStudentAverages($grades, $prevTerm?->id);
        $enrollmentByStudent = $enrollments->groupBy('student_id');
        $violationsByStudent = $points->filter(fn ($p) => (int) $p->points < 0)->groupBy('student_id');

        return $students
            ->map(function ($student) use ($enrollmentByStudent, $violationsByStudent, $current, $previous) {
                $reasons = [];

                $alpa = (int) ($enrollmentByStudent->get($student->id)?->first()->absent_count ?? 0);
                if ($alpa >= self::HIGH_ABSENTEEISM_ALPA) {
                    $reasons[] = 'absenteeism';
                }

                $cur = $current->get($student->id);
                if ($cur !== null && $cur < self::KKM) {
                    $reasons[] = 'below_kkm';
                }

                $prev = $previous->get($student->id);
                $drop = ($cur !== null && $prev !== null) ? round($prev - $cur, 2) : null;
                if ($drop !== null && $drop >= self::GRADE_DROP_POINTS) {
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
    public function perStudentAverages(Collection $termGrades, ?int $termId): Collection
    {
        if (! $termId) {
            return collect();
        }

        return $termGrades
            ->where('term_id', $termId)
            ->groupBy(fn ($g) => $g->student_id.'|'.$g->subject_id)
            ->map(function (Collection $rows) {
                $scores = $rows->pluck('score', 'category')->map(fn ($s) => (float) $s);

                if (array_diff(array_keys(GradeService::WEIGHTS), $scores->keys()->all())) {
                    return null;
                }

                $total = 0.0;
                foreach (GradeService::WEIGHTS as $category => $weight) {
                    $total += (float) $scores[$category] * $weight;
                }

                return round($total, 2);
            })
            ->filter(fn ($final) => $final !== null)
            ->groupBy(fn ($final, string $key) => explode('|', $key)[0])
            ->map(fn (Collection $finals) => round($finals->avg(), 2));
    }

    /** The term before this one: earlier term in the same year, else the previous year's matching/latest term. */
    public function previousTerm(?Term $term): ?Term
    {
        if (! $term) {
            return null;
        }

        $prev = Term::where('academic_year_id', $term->academic_year_id)
            ->where('starts_on', '<', $term->starts_on)
            ->orderByDesc('starts_on')
            ->first();

        if ($prev) {
            return $prev;
        }

        $prevYear = AcademicYear::where('starts_on', '<', $term->academicYear->starts_on)
            ->latest('starts_on')
            ->first();

        if (! $prevYear) {
            return null;
        }

        return $prevYear->terms()->where('name', $term->name)->first()
            ?? $prevYear->terms()->latest('starts_on')->first();
    }
}
