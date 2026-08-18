<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small read-only lists every admin form needs to build a picker from - which
 * units exist, which academic years, which classrooms. None of it is
 * sensitive (units and years are already shared with PMB; a classroom name is
 * not a secret), so it is the same response for both admin kinds rather than
 * a second scoped variant of each.
 */
class ReferenceController extends Controller
{
    public function schoolUnits(): JsonResponse
    {
        return response()->json([
            'school_units' => SchoolUnit::active()->ordered()->get(['ulid', 'code', 'label'])->map(fn (SchoolUnit $u) => [
                'ulid' => $u->ulid, 'code' => $u->code, 'label' => $u->label,
            ]),
        ]);
    }

    public function academicYears(): JsonResponse
    {
        return response()->json([
            'academic_years' => AcademicYear::orderByDesc('year')->get()->map(fn (AcademicYear $y) => [
                'ulid' => $y->ulid, 'year' => $y->year, 'is_active' => $y->is_active,
            ]),
        ]);
    }

    /** Scoped through the same visibleTo() as everywhere else a classroom appears. */
    public function classrooms(Request $request): JsonResponse
    {
        $classrooms = Classroom::query()
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->with('schoolUnit')
            ->orderBy('tingkat')->orderBy('name')
            ->get();

        return response()->json([
            'classrooms' => $classrooms->map(fn (Classroom $c) => [
                'ulid' => $c->ulid, 'name' => $c->name, 'tingkat' => $c->tingkat,
                'school_unit' => ['code' => $c->schoolUnit->code, 'label' => $c->schoolUnit->label],
            ]),
        ]);
    }
}
