<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Models\Extracurricular;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtracurricularController extends Controller
{
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $activities = Extracurricular::query()
            ->whereHas('activeMembers', fn ($q) => $q->where('student_id', $student->id))
            ->with(['schoolUnit', 'pembina'])
            ->get();

        return response()->json([
            'extracurriculars' => $activities->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid,
                'name' => $e->name,
                'pembina' => $e->pembina?->name,
                'school_unit' => $e->schoolUnit?->label,
            ]),
        ]);
    }
}
