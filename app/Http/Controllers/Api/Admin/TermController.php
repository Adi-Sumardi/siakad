<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTermRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The semester flip (December/July) used to be a manual database edit - the
 * audit's "will bite in month six, not day one" item: with no button, grades
 * and points keep filing under last semester and nobody notices for weeks.
 * These two endpoints plus Term::activate() make the flip a one-click,
 * all-or-nothing operation. Central only, same as the academic year it
 * belongs to.
 */
class TermController extends Controller
{
    public function store(StoreTermRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $year = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $term = Term::create([
            'academic_year_id' => $year->id,
            'name' => $validated['name'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'is_active' => false,
        ]);

        if (! empty($validated['is_active'])) {
            $term->activate();
        }

        ActivityLog::record($request->user(), 'term.created', $term, [
            'term' => $term->label(),
            'starts_on' => (string) $validated['starts_on'],
            'ends_on' => (string) $validated['ends_on'],
        ]);

        return response()->json(['term' => $term->fresh()], 201);
    }

    public function activate(Request $request, Term $term): JsonResponse
    {
        $term->activate();

        ActivityLog::record($request->user(), 'term.activated', $term, ['term' => $term->label()]);

        return response()->json([
            'message' => "Semester {$term->label()} sekarang aktif. Semester lain otomatis dinonaktifkan.",
            'term' => $term->fresh(),
        ]);
    }
}
