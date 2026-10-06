<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicPolicy;
use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grade weights + watchlist thresholds (audit 6 Okt 2026 #11-12, T24). Read
 * by every admin (a unit admin sees what their dashboard is measured
 * against); written by the central admin only - it is school policy, and
 * the route group enforces that.
 */
class AcademicPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $units = SchoolUnit::query()
            ->when($user->isUnitScoped(), fn ($q) => $q->whereKey($user->school_unit_id))
            ->orderBy('label')
            ->get();

        $rows = AcademicPolicy::query()->get()->keyBy(fn ($p) => (string) $p->school_unit_id);

        return response()->json([
            'defaults' => AcademicPolicy::DEFAULTS,
            'school_wide' => $this->present(AcademicPolicy::forUnit(null), $rows->has('')),
            'units' => $units->map(fn (SchoolUnit $u) => [
                'unit' => ['ulid' => $u->ulid, 'label' => $u->label],
                ...$this->present(AcademicPolicy::forUnit($u->id), $rows->has((string) $u->id)),
            ]),
            'can_edit' => ! $user->isUnitScoped(),
        ]);
    }

    /** Saves the school-wide row (no unit) or one unit's override. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => 'nullable|string',
            'weight_tugas' => 'required|integer|min:0|max:100',
            'weight_uts' => 'required|integer|min:0|max:100',
            'weight_uas' => 'required|integer|min:0|max:100',
            'kkm' => 'required|integer|min:1|max:100',
            'alpa_threshold' => 'required|integer|min:1|max:365',
            'grade_drop' => 'required|integer|min:1|max:100',
        ]);

        if ($validated['weight_tugas'] + $validated['weight_uts'] + $validated['weight_uas'] !== 100) {
            return response()->json(['message' => 'Bobot Tugas + UTS + UAS harus berjumlah 100%.'], 422);
        }

        $unit = ! empty($validated['unit']) ? SchoolUnit::where('ulid', $validated['unit'])->firstOrFail() : null;

        $policy = AcademicPolicy::updateOrCreate(
            ['school_unit_id' => $unit?->id],
            [...collect($validated)->except('unit')->all(), 'updated_by' => $request->user()->id],
        );

        ActivityLog::record($request->user(), 'academic_policy.updated', $policy, [
            'unit' => $unit?->label ?? 'semua unit',
            ...collect($validated)->except('unit')->all(),
        ]);

        return $this->index($request);
    }

    /** Drops a unit's override so it follows the school-wide policy again. */
    public function destroy(Request $request, string $unitUlid): JsonResponse
    {
        $unit = SchoolUnit::where('ulid', $unitUlid)->firstOrFail();

        AcademicPolicy::where('school_unit_id', $unit->id)->first()?->delete();

        ActivityLog::record($request->user(), 'academic_policy.reset', null, ['unit' => $unit->label]);

        return $this->index($request);
    }

    private function present(AcademicPolicy $p, bool $stored): array
    {
        return [
            'overridden' => $stored,
            'weight_tugas' => $p->weight_tugas,
            'weight_uts' => $p->weight_uts,
            'weight_uas' => $p->weight_uas,
            'kkm' => $p->kkm,
            'alpa_threshold' => $p->alpa_threshold,
            'grade_drop' => $p->grade_drop,
        ];
    }
}
