<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
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
            'academic_years' => AcademicYear::orderByDesc('is_active')->orderBy('year')->get()->map(fn (AcademicYear $y) => [
                'ulid' => $y->ulid,
                'year' => $y->year,
                'starts_on' => $y->starts_on?->format('Y-m-d'),
                'ends_on' => $y->ends_on?->format('Y-m-d'),
                'is_active' => $y->is_active,
            ]),
        ]);
    }

    public function storeAcademicYear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|string|regex:/^\d{4}\/\d{4}$/|unique:academic_years,year',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'is_active' => 'boolean',
        ]);

        $startsOn = $validated['starts_on'] ?? substr($validated['year'], 0, 4) . '-07-01';
        $endsOn = $validated['ends_on'] ?? substr($validated['year'], 5, 4) . '-06-30';

        $year = AcademicYear::create([
            'year' => $validated['year'],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'is_active' => false,
        ]);

        if (! empty($validated['is_active'])) {
            $year->activate();
        }

        ActivityLog::record($request->user(), 'academic_year.created', $year, ['year' => $year->year]);

        return response()->json(['academic_year' => $year->fresh()], 201);
    }

    public function activateAcademicYear(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $academicYear->activate();

        ActivityLog::record($request->user(), 'academic_year.activated', $academicYear, ['year' => $academicYear->year]);

        return response()->json([
            'message' => "Tahun ajaran {$academicYear->year} sekarang aktif.",
            'academic_year' => $academicYear->fresh(),
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
