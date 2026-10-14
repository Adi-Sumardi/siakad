<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\SaveGradeSheetRequest;
use App\Http\Requests\Guru\StoreGradeAssignmentRequest;
use App\Http\Requests\Guru\StoreGradesRequest;
use App\Models\AcademicPolicy;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\GradeAssignment;
use App\Models\GradeAssignmentScore;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Academic\GradeService;
use App\Services\Academic\RaporPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class GradeController extends Controller
{
    /** The (classroom, subject) combinations class_schedules says this teacher teaches - the only "teaching assignments" list in the app. */
    public function myAssignments(Request $request): JsonResponse
    {
        $activeYearId = AcademicYear::current()?->id;

        // Running year only - last year's timetable rows are still on file,
        // and without this every card showed up twice (8-A of each year).
        $schedules = ClassSchedule::where('teacher_id', $request->user()->id)
            ->when($activeYearId, fn ($q) => $q->whereHas('classroom', fn ($c) => $c->where('academic_year_id', $activeYearId)))
            ->with('classroom', 'subject')
            ->get()
            ->unique(fn (ClassSchedule $s) => $s->classroom_id.'-'.$s->subject_id)
            // Grouped by class, so one class's subjects sit side by side.
            ->sortBy(fn (ClassSchedule $s) => [$s->classroom->tingkat, $s->classroom->name, $s->subject->name])
            ->values();

        return response()->json([
            'assignments' => $schedules->map(fn (ClassSchedule $s) => [
                'classroom' => ['ulid' => $s->classroom->ulid, 'name' => $s->classroom->name],
                'subject' => ['ulid' => $s->subject->ulid, 'name' => $s->subject->name],
            ]),
        ]);
    }

    /**
     * The whole gradebook for the current term: every assignment column with
     * each student's score on it, the averaged tugas, UTS, UAS and the
     * weighted final - one screen instead of one category at a time.
     */
    public function roster(Request $request, string $classroomUlid, string $subjectUlid, GradeService $service): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $subject = Subject::where('ulid', $subjectUlid)->firstOrFail();

        abort_unless($service->canGrade($request->user(), $classroom, $subject), 403, 'Anda tidak ditugaskan mengajar mata pelajaran ini di kelas ini.');

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        $grades = Grade::where('classroom_id', $classroom->id)
            ->where('subject_id', $subject->id)
            ->where('term_id', $term->id)
            ->get()
            ->groupBy('student_id');

        $assignments = $service->assignmentsFor($classroom, $subject, $term);
        $assignmentScores = GradeAssignmentScore::whereIn('grade_assignment_id', $assignments->pluck('id'))
            ->get()
            ->groupBy('student_id');
        $assignmentUlids = $assignments->pluck('ulid', 'id');

        $policy = AcademicPolicy::forUnit($classroom->school_unit_id);
        $weights = $policy->weights();

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'subject' => ['ulid' => $subject->ulid, 'name' => $subject->name],
            'term' => ['ulid' => $term->ulid, 'name' => $term->name],
            // False in the post-rollover window - the sheet shows read-only
            // instead of letting the teacher type into a save that 422s.
            'editable' => $term->academic_year_id === $classroom->academic_year_id,
            'weights' => $weights,
            'kkm' => $policy->kkm,
            'assignments' => $assignments->map(fn (GradeAssignment $a) => [
                'ulid' => $a->ulid,
                'title' => $a->title,
                'assigned_on' => $a->assigned_on?->toDateString(),
            ])->values(),
            'students' => $students->map(function ($student) use ($grades, $assignmentScores, $assignmentUlids, $weights) {
                $byCategory = ($grades->get($student->id) ?? collect())->keyBy('category');
                $scores = ($grades->get($student->id) ?? collect())->pluck('score', 'category');

                return [
                    'ulid' => $student->ulid,
                    'nama_lengkap' => $student->nama_lengkap,
                    'nis' => $student->nis,
                    // Keyed by assignment ULID; a missing key is an empty cell.
                    'tugas_scores' => ($assignmentScores->get($student->id) ?? collect())
                        ->mapWithKeys(fn ($s) => [$assignmentUlids[$s->grade_assignment_id] => (float) $s->score]),
                    'tugas' => isset($byCategory['tugas']) ? (float) $byCategory['tugas']->score : null,
                    'uts' => isset($byCategory['uts']) ? (float) $byCategory['uts']->score : null,
                    'uas' => isset($byCategory['uas']) ? (float) $byCategory['uas']->score : null,
                    'final' => GradeService::weighted($scores, $weights),
                ];
            }),
        ]);
    }

    /** Adds a Tugas column to the gradebook. */
    public function storeAssignment(StoreGradeAssignmentRequest $request, string $classroomUlid, string $subjectUlid, GradeService $service): JsonResponse
    {
        [$classroom, $subject, $term] = $this->writableGradebook($request, $classroomUlid, $subjectUlid, $service);

        $validated = $request->validated();
        $assignment = $service->createAssignment($classroom, $subject, $term, $validated['title'], $validated['assigned_on'] ?? null, $request->user());

        ActivityLog::record($request->user(), 'grade.assignment_created', null, [
            'classroom' => $classroom->name, 'subject' => $subject->name, 'title' => $assignment->title,
        ]);

        return response()->json(['assignment' => [
            'ulid' => $assignment->ulid,
            'title' => $assignment->title,
            'assigned_on' => $assignment->assigned_on?->toDateString(),
        ]], 201);
    }

    /** Renames / re-dates a Tugas column. */
    public function updateAssignment(StoreGradeAssignmentRequest $request, string $ulid, GradeService $service): JsonResponse
    {
        $assignment = $this->ownAssignment($request, $ulid, $service);

        $validated = $request->validated();
        $assignment->update(['title' => $validated['title'], 'assigned_on' => $validated['assigned_on'] ?? null]);

        return response()->json(['assignment' => [
            'ulid' => $assignment->ulid,
            'title' => $assignment->title,
            'assigned_on' => $assignment->assigned_on?->toDateString(),
        ]]);
    }

    /** Drops a Tugas column with its scores; tugas averages are recomputed. */
    public function destroyAssignment(Request $request, string $ulid, GradeService $service): JsonResponse
    {
        $assignment = $this->ownAssignment($request, $ulid, $service);

        ActivityLog::record($request->user(), 'grade.assignment_deleted', null, [
            'classroom' => $assignment->classroom->name, 'subject' => $assignment->subject->name,
            'title' => $assignment->title, 'score_count' => $assignment->scores()->count(),
        ]);

        $service->deleteAssignment($assignment, $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * Saves every changed cell of the sheet at once - assignment scores, UTS,
     * UAS. A null clears the cell; a key the client leaves out is untouched.
     */
    public function saveSheet(SaveGradeSheetRequest $request, string $classroomUlid, string $subjectUlid, GradeService $service): JsonResponse
    {
        [$classroom, $subject, $term] = $this->writableGradebook($request, $classroomUlid, $subjectUlid, $service);

        $validated = $request->validated();
        $ulids = collect($validated['entries'])->pluck('student_ulid');

        // Same guard as store(): only this room's own active students.
        $students = Student::whereIn('ulid', $ulids)
            ->whereHas('enrollments', fn ($q) => $q->where('classroom_id', $classroom->id)->where('status', 'active'))
            ->get()->keyBy('ulid');

        if ($students->count() !== $ulids->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan bagian dari kelas ini.'], 422);
        }

        $assignmentIds = $service->assignmentsFor($classroom, $subject, $term)->pluck('id', 'ulid');

        $entries = collect();
        foreach ($validated['entries'] as $e) {
            $entry = ['student' => $students[$e['student_ulid']]];

            foreach ($e['tugas'] ?? [] as $assignmentUlid => $score) {
                if (! $assignmentIds->has($assignmentUlid)) {
                    return response()->json(['message' => 'Kolom tugas tidak ditemukan - muat ulang halaman.'], 422);
                }
                $entry['tugas'][$assignmentIds[$assignmentUlid]] = $score === null ? null : (float) $score;
            }

            foreach (['uts', 'uas'] as $category) {
                if (array_key_exists($category, $e)) {
                    $entry[$category] = $e[$category] === null ? null : (float) $e[$category];
                }
            }

            $entries->push($entry);
        }

        $touched = $service->saveSheet($classroom, $subject, $term, $entries, $request->user());

        ActivityLog::record($request->user(), 'grade.sheet_saved', null, [
            'classroom' => $classroom->name, 'subject' => $subject->name,
            'student_count' => $entries->count(), 'cell_count' => $touched,
        ]);

        return response()->json(['saved' => $touched, 'student_count' => $entries->count()]);
    }

    /**
     * Classroom + subject + running term, checked exactly as store() always
     * has: scheduled teacher only, and the lit term must belong to the
     * room's own year (frozen after rollover, audit T48-b).
     *
     * @return array{0: Classroom, 1: Subject, 2: Term}
     */
    private function writableGradebook(Request $request, string $classroomUlid, string $subjectUlid, GradeService $service): array
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $subject = Subject::where('ulid', $subjectUlid)->firstOrFail();

        abort_unless($service->canGrade($request->user(), $classroom, $subject), 403, 'Anda tidak ditugaskan mengajar mata pelajaran ini di kelas ini.');

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');
        abort_if(
            $term->academic_year_id !== $classroom->academic_year_id,
            422,
            'Semester aktif bukan milik tahun ajaran kelas ini. Nilai kelas tahun lama dibekukan setelah pergantian tahun ajaran.',
        );

        return [$classroom, $subject, $term];
    }

    /** An assignment of the running term that this teacher is scheduled to grade. */
    private function ownAssignment(Request $request, string $ulid, GradeService $service): GradeAssignment
    {
        $assignment = GradeAssignment::where('ulid', $ulid)->with('classroom', 'subject', 'term')->firstOrFail();

        $classroom = Classroom::visibleTo($request->user())->whereKey($assignment->classroom_id)->firstOrFail();

        abort_unless($service->canGrade($request->user(), $classroom, $assignment->subject), 403, 'Anda tidak ditugaskan mengajar mata pelajaran ini di kelas ini.');
        abort_unless($assignment->term_id === Term::current()?->id, 422, 'Tugas semester lalu tidak bisa diubah.');

        return $assignment;
    }

    public function store(StoreGradesRequest $request, string $classroomUlid, string $subjectUlid, GradeService $service): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $subject = Subject::where('ulid', $subjectUlid)->firstOrFail();

        abort_unless($service->canGrade($request->user(), $classroom, $subject), 403, 'Anda tidak ditugaskan mengajar mata pelajaran ini di kelas ini.');

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        // The lit semester must belong to this classroom's year (audit
        // T48-b): in the post-rollover window (new year active, promotion
        // not yet run) old-year classrooms still pass canGrade, and grades
        // filed then carried the NEW term id - surfacing in the new year's
        // rapor for students who had not even been promoted yet.
        abort_if(
            $term->academic_year_id !== $classroom->academic_year_id,
            422,
            'Semester aktif bukan milik tahun ajaran kelas ini. Nilai kelas tahun lama dibekukan setelah pergantian tahun ajaran.',
        );

        $validated = $request->validated();

        // Once the gradebook has assignment columns, tugas is their average -
        // a directly typed tugas would be overwritten on the next save.
        if ($validated['category'] === 'tugas' && $service->assignmentsFor($classroom, $subject, $term)->isNotEmpty()) {
            return response()->json(['message' => 'Nilai tugas dihitung dari rata-rata daftar tugas. Isi nilai pada kolom tugasnya.'], 422);
        }

        $ulids = collect($validated['entries'])->pluck('student_ulid');

        // Being authorized for (this classroom, this subject) does not mean
        // every student in the unit is fair game - Student::visibleTo() is
        // unit-wide, and grades.upsertBulk() has no classroom-aware unique
        // key. Without this, a teacher assigned to classroom A could
        // silently overwrite a student's grade in classroom B for a subject
        // taught in both.
        $students = Student::whereIn('ulid', $ulids)
            ->whereHas('enrollments', fn ($q) => $q->where('classroom_id', $classroom->id)->where('status', 'active'))
            ->get()->keyBy('ulid');

        if ($students->count() !== $ulids->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan bagian dari kelas ini.'], 422);
        }

        $entries = collect($validated['entries'])->map(fn ($e) => [
            'student' => $students[$e['student_ulid']],
            'score' => (float) $e['score'],
            'description' => $e['description'] ?? null,
        ]);

        try {
            $grades = $service->upsertBulk($classroom, $subject, $term, $validated['category'], $entries, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'grade.recorded_bulk', null, [
            'classroom' => $classroom->name, 'subject' => $subject->name,
            'category' => $validated['category'], 'student_count' => $grades->count(),
        ]);

        return response()->json(['recorded' => $grades->count()], 201);
    }

    /**
     * The whole-class grade matrix for the running term: every scheduled
     * subject x every roster student, all three categories side by side
     * with the weighted final. Read-only, so unlike roster()/store() there
     * is no canGrade() gate - any teacher of the unit can look at a room
     * they can already open (the same line attendanceRecap() draws);
     * entering grades stays scheduled-teachers-only.
     */
    public function classRecap(Request $request, string $classroomUlid, GradeService $service): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            ...$service->classRecap($classroom, $term),
        ]);
    }

    /**
     * The same report card the guardian will eventually download, so the
     * teacher can check it before it reaches them - the B.2 audit gap. Same
     * read line as classRecap(): any teacher of the unit, not only the
     * scheduled one, since reading never edits. Rendered on demand, nothing
     * stored (D9/R9).
     */
    public function rapor(Request $request, string $studentUlid, RaporPdfService $pdf, GradeService $service): Response
    {
        $student = Student::visibleTo($request->user())->where('ulid', $studentUlid)->firstOrFail();

        $termUlid = $request->string('term_ulid')->value();
        $term = $termUlid ? Term::where('ulid', $termUlid)->first() : Term::current();

        abort_if(! $term, 422, 'Belum ada semester yang bisa dipilih.');

        return $pdf->render($student, $term, $service)->stream($pdf->filename($student, $term));
    }
}
