<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The catalogue admins and teachers draw from. A central admin manages the
 * school-wide rules and can also touch any unit's; a per-unit admin is
 * confined to their own unit's rules and can never create or edit a
 * school-wide one - the same split FeeSettingController draws around who may
 * change what everyone is charged.
 */
class PointRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = PointRule::query()
            ->with('schoolUnit')
            ->when($request->user()->isUnitScoped(), fn ($q) => $q
                ->where(fn ($w) => $w->where('school_unit_id', $request->user()->school_unit_id)->orWhereNull('school_unit_id')))
            ->orderBy('type')->orderBy('sort_order')
            ->get();

        return response()->json([
            'rules' => $rules->map(fn (PointRule $r) => [
                'ulid' => $r->ulid,
                'school_unit' => $r->schoolUnit?->label,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'category' => $r->category,
                'points' => $r->points,
                'requires_evidence' => $r->requires_evidence,
                'is_active' => $r->is_active,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'school_unit_code' => 'nullable|exists:school_units,code',
            'code' => 'required|string|max:32|alpha_dash',
            'name' => 'required|string|max:120',
            'type' => 'required|in:violation,merit',
            'category' => 'required|string|max:60',
            'points' => 'required|integer|min:1|max:200',
            'requires_evidence' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $unit = $this->resolveUnit($request, $validated['school_unit_code'] ?? null);

        if ($request->user()->isUnitScoped() && ! $unit) {
            // A per-unit admin has no path to a school-wide rule at all - not
            // even by omitting the unit, which for a central admin means
            // exactly that.
            return response()->json(['message' => 'Aturan wajib untuk unit Anda sendiri.'], 422);
        }

        $clash = PointRule::where('school_unit_id', $unit?->id)->where('code', $validated['code'])->exists();

        if ($clash) {
            return response()->json(['message' => 'Kode aturan ini sudah dipakai untuk cakupan yang sama.'], 422);
        }

        $rule = PointRule::create([
            'school_unit_id' => $unit?->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'category' => $validated['category'],
            'points' => $validated['points'],
            'requires_evidence' => $validated['requires_evidence'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        ActivityLog::record($request->user(), 'point_rule.created', $rule, ['code' => $rule->code]);

        return response()->json(['rule' => $rule], 201);
    }

    public function update(Request $request, PointRule $pointRule): JsonResponse
    {
        $this->authoriseScope($request, $pointRule);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'category' => 'sometimes|string|max:60',
            'points' => 'sometimes|integer|min:1|max:200',
            'requires_evidence' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $pointRule->update($validated);

        ActivityLog::record($request->user(), 'point_rule.updated', $pointRule, $validated);

        return response()->json(['rule' => $pointRule->fresh()]);
    }

    public function destroy(Request $request, PointRule $pointRule): JsonResponse
    {
        $this->authoriseScope($request, $pointRule);

        if ($pointRule->records()->exists()) {
            // A rule already applied to a real event stays in the catalogue -
            // deleting it would strip the description off every ledger row
            // that points at it. Deactivate instead.
            return response()->json([
                'message' => 'Aturan ini sudah pernah dipakai mencatat poin. Nonaktifkan, bukan hapus.',
            ], 422);
        }

        ActivityLog::record($request->user(), 'point_rule.deleted', $pointRule, ['code' => $pointRule->code]);
        $pointRule->delete();

        return response()->json(['message' => 'Aturan dihapus.']);
    }

    private function resolveUnit(Request $request, ?string $code): ?SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            return $request->user()->schoolUnit;
        }

        return $code ? SchoolUnit::findByCode($code) : null;
    }

    private function authoriseScope(Request $request, PointRule $rule): void
    {
        $user = $request->user();

        abort_if(
            $user->isUnitScoped() && $rule->school_unit_id !== $user->school_unit_id,
            404,
        );
    }
}
