<?php

namespace App\Services\Academic;

use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of grades. Unlike point_records/attendance_records this is
 * a plain upsert, not a revoke-ledger - a grade is always entered by an
 * authenticated, authorized teacher correcting their own work, not an
 * anonymous public check-in, so recorded_by + updated_at is accountability
 * enough. class_schedules.teacher_id is the only "teaching assignment"
 * record in the app - there is no separate table for it.
 */
class GradeService
{
    /**
     * Standard Indonesian weighting (Tugas 20% / UTS 30% / UAS 50%) - an
     * assumption, not a confirmed school policy. Easy to change here later;
     * not worth a configuration screen until a school actually asks for a
     * different split.
     */
    public const WEIGHTS = ['tugas' => 0.2, 'uts' => 0.3, 'uas' => 0.5];

    /** Whether this teacher is the one class_schedules says teaches this subject in this classroom - the sole authorization check for grade entry. */
    public function canGrade(User $teacher, Classroom $classroom, Subject $subject): bool
    {
        return ClassSchedule::where('classroom_id', $classroom->id)
            ->where('subject_id', $subject->id)
            ->where('teacher_id', $teacher->id)
            ->exists();
    }

    /**
     * @param  Collection<int, array{student: Student, score: float, description?: ?string}>  $entries
     * @return Collection<int, Grade>
     */
    public function upsertBulk(
        Classroom $classroom,
        Subject $subject,
        Term $term,
        string $category,
        Collection $entries,
        User $recordedBy,
    ): Collection {
        foreach ($entries as $entry) {
            if ($entry['score'] < 0 || $entry['score'] > 100) {
                throw new RuntimeException("Nilai untuk {$entry['student']->nama_lengkap} harus antara 0 dan 100.");
            }
        }

        return DB::transaction(function () use ($classroom, $subject, $term, $category, $entries, $recordedBy) {
            return $entries->map(function (array $entry) use ($classroom, $subject, $term, $category, $recordedBy) {
                /** @var Student $student */
                $student = $entry['student'];

                return Grade::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'subject_id' => $subject->id,
                        'term_id' => $term->id,
                        'category' => $category,
                    ],
                    [
                        'classroom_id' => $classroom->id,
                        'score' => $entry['score'],
                        'description' => $entry['description'] ?? null,
                        'recorded_by' => $recordedBy->id,
                    ],
                );
            });
        });
    }

    /** Null when any of the three categories hasn't been entered yet - never a guess from a partial average. */
    public function finalScore(Student $student, Subject $subject, Term $term): ?float
    {
        $scores = Grade::where('student_id', $student->id)
            ->where('subject_id', $subject->id)
            ->where('term_id', $term->id)
            ->pluck('score', 'category');

        if (array_diff(array_keys(self::WEIGHTS), $scores->keys()->all())) {
            return null;
        }

        $total = 0.0;
        foreach (self::WEIGHTS as $category => $weight) {
            $total += (float) $scores[$category] * $weight;
        }

        return round($total, 2);
    }

    /** Every subject scheduled for the student's current classroom, with its three category scores and computed final. */
    public function summaryForRapor(Student $student, Term $term): Collection
    {
        $classroom = $student->currentEnrollment()?->classroom;

        if (! $classroom) {
            return collect();
        }

        $subjects = ClassSchedule::where('classroom_id', $classroom->id)
            ->with('subject')
            ->get()
            ->pluck('subject')
            ->unique('id')
            ->sortBy('name')
            ->values();

        return $subjects->map(function (Subject $subject) use ($student, $term) {
            $scores = Grade::where('student_id', $student->id)
                ->where('subject_id', $subject->id)
                ->where('term_id', $term->id)
                ->pluck('score', 'category');

            return [
                'subject' => ['ulid' => $subject->ulid, 'name' => $subject->name],
                'tugas' => isset($scores['tugas']) ? (float) $scores['tugas'] : null,
                'uts' => isset($scores['uts']) ? (float) $scores['uts'] : null,
                'uas' => isset($scores['uas']) ? (float) $scores['uas'] : null,
                'final' => $this->finalScore($student, $subject, $term),
            ];
        });
    }
}
