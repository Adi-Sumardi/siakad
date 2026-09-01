<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\Academic\PromotionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PromotionController extends Controller
{
    /** Who's eligible to be promoted out of this classroom - its own active roster, for its own academic year. */
    public function roster(Request $request, string $classroomUlid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        return response()->json([
            'classroom' => [
                'ulid' => $classroom->ulid, 'name' => $classroom->name, 'tingkat' => $classroom->tingkat,
                'academic_year' => $classroom->academicYear?->year,
            ],
            'students' => $students->map(fn (Student $s) => [
                'ulid' => $s->ulid, 'nama_lengkap' => $s->nama_lengkap, 'nis' => $s->nis,
            ]),
        ]);
    }

    public function targets(Request $request, string $classroomUlid, PromotionService $service): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_ulid' => 'required|string',
            'outcome' => 'required|in:promoted,repeated',
        ]);

        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $newYear = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $groups = $service->eligibleTargetClassrooms($classroom, $newYear, $validated['outcome']);

        $shape = fn ($classrooms) => $classrooms->map(fn (Classroom $c) => [
            'ulid' => $c->ulid, 'name' => $c->name, 'tingkat' => $c->tingkat,
            'school_unit' => ['code' => $c->schoolUnit->code, 'label' => $c->schoolUnit->label],
        ]);

        return response()->json([
            'same_unit' => $shape($groups['same_unit']),
            'other' => $shape($groups['other']),
        ]);
    }

    public function store(Request $request, string $classroomUlid, PromotionService $service): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_ulid' => 'required|string',
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_ulid' => 'required|string',
            'entries.*.outcome' => 'required|in:promoted,repeated,graduated,left',
            'entries.*.target_classroom_ulid' => 'nullable|string',
        ]);

        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();
        $newYear = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $studentUlids = collect($validated['entries'])->pluck('student_ulid');
        $students = Student::visibleTo($request->user())->whereIn('ulid', $studentUlids)->get()->keyBy('ulid');

        if ($students->count() !== $studentUlids->unique()->count()) {
            return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan wewenang Anda.'], 422);
        }

        $targetUlids = collect($validated['entries'])->pluck('target_classroom_ulid')->filter()->unique();
        $targets = Classroom::whereIn('ulid', $targetUlids)->get()->keyBy('ulid');

        $entries = collect($validated['entries'])->map(fn ($e) => [
            'student' => $students[$e['student_ulid']],
            'outcome' => $e['outcome'],
            'target_classroom' => isset($e['target_classroom_ulid']) ? $targets->get($e['target_classroom_ulid']) : null,
        ]);

        try {
            $results = $service->promoteBatch($classroom, $newYear, $entries, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            // assertValidTarget()'s own "already enrolled" check is a
            // read-then-write - two admins promoting overlapping students in
            // the same classroom at once can both pass it and then collide
            // on enrollments' (student_id, academic_year_id) unique
            // constraint. The whole batch already rolled back atomically;
            // this just keeps that from surfacing as a raw 500.
            return response()->json([
                'message' => 'Sebagian siswa mungkin sudah diproses oleh admin lain secara bersamaan. Muat ulang dan coba lagi.',
            ], 409);
        }

        ActivityLog::record($request->user(), 'promotion.executed', $classroom, [
            'academic_year' => $newYear->year, 'student_count' => $results->count(),
        ]);

        return response()->json(['promoted' => $results->count()], 201);
    }
}
