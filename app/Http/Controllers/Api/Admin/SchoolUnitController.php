<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for the unit master - central-admin only (route gated, see
 * routes/api.php). Was hardcoded/DB-only before this: the only way to add,
 * rename, or retire a campus was a raw SQL statement against production,
 * which is exactly how school_units ended up with two seedings of the same
 * eight campuses (see migration 2026_09_02_000001_dedupe_school_units).
 */
class SchoolUnitController extends Controller
{
    private const RULES = [
        'code' => 'required|string|max:100|alpha_dash',
        'label' => 'required|string|max:255',
        'jenjang_group' => 'required|in:ra,pg,tk,sd,smp,sma',
        'is_active' => 'required|boolean',
        'sort_order' => 'nullable|integer|min:0',
    ];

    /** Full management list - active and inactive both, unlike ReferenceController::schoolUnits()'s picker-only slice. */
    public function index(): JsonResponse
    {
        return response()->json([
            'school_units' => SchoolUnit::ordered()->get()->map(fn (SchoolUnit $u) => [
                'ulid' => $u->ulid,
                'code' => $u->code,
                'label' => $u->label,
                'jenjang_group' => $u->jenjang_group,
                'is_active' => $u->is_active,
                'sort_order' => $u->sort_order,
                'student_count' => $u->students()->count(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(self::RULES, [
            'code' => self::RULES['code'].'|unique:school_units,code',
        ]));

        $unit = SchoolUnit::create($validated);

        ActivityLog::record($request->user(), 'school_unit.created', $unit, ['code' => $unit->code, 'label' => $unit->label]);

        return response()->json(['message' => 'Unit sekolah berhasil ditambahkan.', 'school_unit' => $unit], 201);
    }

    public function update(Request $request, SchoolUnit $schoolUnit): JsonResponse
    {
        $validated = $request->validate(array_merge(self::RULES, [
            'code' => self::RULES['code'].'|unique:school_units,code,'.$schoolUnit->id,
        ]));

        $schoolUnit->update($validated);

        ActivityLog::record($request->user(), 'school_unit.updated', $schoolUnit, $validated);

        return response()->json(['message' => 'Unit sekolah berhasil diperbarui.', 'school_unit' => $schoolUnit->fresh()]);
    }

    /**
     * students.school_unit_id is ON DELETE RESTRICT (see the dedupe
     * migration's own check of pg_constraint before it moved anything) -
     * the database itself refuses this once a real student is enrolled, so
     * the only thing to do here is turn that into a message an admin can
     * act on instead of a raw SQL error.
     */
    public function destroy(Request $request, SchoolUnit $schoolUnit): JsonResponse
    {
        try {
            $schoolUnit->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true)) {
                return response()->json([
                    'message' => "Unit \"{$schoolUnit->label}\" masih punya siswa terdaftar - pindahkan siswanya ke unit lain dulu, atau nonaktifkan saja unit ini.",
                ], 422);
            }

            throw $e;
        }

        ActivityLog::record($request->user(), 'school_unit.deleted', $schoolUnit, ['code' => $schoolUnit->code, 'label' => $schoolUnit->label]);

        return response()->json(['message' => 'Unit sekolah berhasil dihapus.']);
    }
}
