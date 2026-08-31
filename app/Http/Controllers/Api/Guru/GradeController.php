<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Academic\GradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GradeController extends Controller
{
    /** The (classroom, subject) combinations class_schedules says this teacher teaches - the only "teaching assignments" list in the app. */
    public function myAssignments(Request $request): JsonResponse
    {
        $schedules = ClassSchedule::where('teacher_id', $request->user()->id)
            ->with('classroom', 'subject')
            ->get()
            ->unique(fn (ClassSchedule $s) => $s->classroom_id.'-'.$s->subject_id)
            ->values();

        return response()->json([
            'assignments' => $schedules->map(fn (ClassSchedule $s) => [
                'classroom' => ['ulid' => $s->classroom->ulid, 'name' => $s->classroom->name],
                'subject' => ['ulid' => $s->subject->ulid, 'name' => $s->subject->name],
            ]),
        ]);
    }

    /** Roster + whatever the three categories already hold for the current term. */
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

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'subject' => ['ulid' => $subject->ulid, 'name' => $subject->name],
            'students' => $students->map(function ($student) use ($grades) {
                $byCategory = ($grades->get($student->id) ?? collect())->keyBy('category');

                return [
                    'ulid' => $student->ulid,
                    'nama_lengkap' => $student->nama_lengkap,
                    'nis' => $student->nis,
                    'tugas' => isset($byCategory['tugas']) ? (float) $byCategory['tugas']->score : null,
                    'uts' => isset($byCategory['uts']) ? (float) $byCategory['uts']->score : null,
                    'uas' => isset($byCategory['uas']) ? (float) $byCategory['uas']->score : null,
                ];
            }),
        ]);
    }

    public function store(Request $request, string $classroomUlid, string $subjectUlid, GradeService $service): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $subject = Subject::where('ulid', $subjectUlid)->firstOrFail();

        abort_unless($service->canGrade($request->user(), $classroom, $subject), 403, 'Anda tidak ditugaskan mengajar mata pelajaran ini di kelas ini.');

        $term = Term::current();
        abort_if(! $term, 422, 'Belum ada semester aktif.');

        $validated = $request->validate([
            'category' => 'required|in:tugas,uts,uas',
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_ulid' => 'required|string',
            'entries.*.score' => 'required|numeric|min:0|max:100',
            'entries.*.description' => 'nullable|string|max:500',
        ]);

        $ulids = collect($validated['entries'])->pluck('student_ulid');
        $students = Student::visibleTo($request->user())->whereIn('ulid', $ulids)->get()->keyBy('ulid');

        if ($students->count() !== $ulids->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan wewenang Anda.'], 422);
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
}
