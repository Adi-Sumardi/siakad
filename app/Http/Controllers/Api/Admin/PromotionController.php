<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromotionTargetsRequest;
use App\Http\Requests\Admin\StorePromotionRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
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

        // Outstanding bills follow the student silently (audit T49-c): the
        // money stays attached whichever classroom they land in, but the
        // operator used to get no signal at all before moving a whole
        // debtor cohort into the next year.
        $debtorIds = Bill::whereIn('student_id', $students->pluck('id'))
            ->open()
            ->pluck('student_id')
            ->unique();

        return response()->json([
            'classroom' => [
                'ulid' => $classroom->ulid, 'name' => $classroom->name, 'tingkat' => $classroom->tingkat,
                'academic_year' => $classroom->academicYear?->year,
            ],
            'students' => $students->map(fn (Student $s) => [
                'ulid' => $s->ulid, 'nama_lengkap' => $s->nama_lengkap, 'nis' => $s->nis,
                'has_open_bills' => $debtorIds->contains($s->id),
            ]),
        ]);
    }

    public function targets(PromotionTargetsRequest $request, string $classroomUlid, PromotionService $service): JsonResponse
    {
        $validated = $request->validated();

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

    public function store(StorePromotionRequest $request, string $classroomUlid, PromotionService $service): JsonResponse
    {
        $validated = $request->validated();

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

    /**
     * Reverts a classroom's executed promotion (audit T49-a) - the undo a
     * wrong batch never had (database surgery was the only option). Whole
     * batch or nothing per student: someone who already has grades or
     * bills in the target year is skipped and named, never orphaned.
     */
    public function undo(Request $request, string $classroomUlid, PromotionService $service): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $classroomUlid)->firstOrFail();

        try {
            $result = $service->undoBatch($classroom, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'undone' => $result['undone'],
            'skipped' => $result['skipped'],
            'message' => "Promosi {$result['undone']} siswa dibatalkan."
                .($result['skipped'] !== [] ? ' Dilewati: '.implode(', ', $result['skipped']).'.' : ''),
        ]);
    }
}
