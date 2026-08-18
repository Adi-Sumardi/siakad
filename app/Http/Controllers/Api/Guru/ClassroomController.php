<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    /**
     * Every classroom in the teacher's unit, not only the one they are
     * homeroom of - Classroom::scopeVisibleTo() already draws that line
     * (teachers teach across a unit, homeroom is just their own room), so this
     * simply reads it rather than narrowing further.
     */
    public function index(Request $request): JsonResponse
    {
        $classrooms = Classroom::query()
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->with('homeroomTeacher')
            ->orderBy('tingkat')->orderBy('name')
            ->get();

        return response()->json([
            'classrooms' => $classrooms->map(fn (Classroom $c) => [
                'ulid' => $c->ulid,
                'name' => $c->name,
                'tingkat' => $c->tingkat,
                'is_homeroom' => $c->homeroom_teacher_id === $request->user()->id,
                'homeroom_teacher' => $c->homeroomTeacher?->name,
                'student_count' => $c->enrollments()->where('status', 'active')->count(),
            ]),
        ]);
    }

    /** The roster, each student's running point balance for the term alongside it. */
    public function students(Request $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        $term = Term::current();
        $ledger = app(PointLedger::class);

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'students' => $students->map(fn ($student) => [
                'ulid' => $student->ulid,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
                'point_balance' => $term ? $ledger->balance($student, $term) : null,
            ]),
        ]);
    }
}
