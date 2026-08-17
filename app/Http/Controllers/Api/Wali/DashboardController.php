<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * The guardian's children, whichever unit each of them attends.
     *
     * Scoped through Student::visibleTo() rather than by walking the
     * relationship by hand, so this screen obeys the same rule as every other
     * one and cannot drift from it.
     */
    public function index(Request $request): JsonResponse
    {
        $students = Student::query()
            ->visibleTo($request->user())
            ->with(['schoolUnit', 'enrollments.classroom.homeroomTeacher'])
            ->orderBy('nama_lengkap')
            ->get();

        return response()->json([
            'students' => StudentResource::collection($students),
        ]);
    }
}
