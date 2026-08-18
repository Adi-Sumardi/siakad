<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PointThreshold;
use App\Models\SchoolUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PointThresholdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $thresholds = PointThreshold::query()
            ->with('schoolUnit')
            ->when($request->user()->isUnitScoped(), fn ($q) => $q
                ->where(fn ($w) => $w->where('school_unit_id', $request->user()->school_unit_id)->orWhereNull('school_unit_id')))
            ->orderByDesc('min_points')
            ->get();

        return response()->json([
            'thresholds' => $thresholds->map(fn (PointThreshold $t) => [
                'ulid' => $t->ulid,
                'school_unit' => $t->schoolUnit?->label,
                'min_points' => $t->min_points,
                'max_points' => $t->max_points,
                'label' => $t->label,
                'action' => $t->action,
                'color' => $t->color,
                'notify_guardian' => $t->notify_guardian,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'school_unit_code' => 'nullable|exists:school_units,code',
            'min_points' => 'required|integer|lt:max_points',
            'max_points' => 'required|integer',
            'label' => 'required|string|max:80',
            'action' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:20',
            'notify_guardian' => 'boolean',
        ]);

        $unit = $request->user()->isUnitScoped()
            ? $request->user()->schoolUnit
            : ($validated['school_unit_code'] ?? null ? SchoolUnit::findByCode($validated['school_unit_code']) : null);

        abort_if($request->user()->isUnitScoped() && ! $unit, 422, 'Ambang wajib untuk unit Anda sendiri.');

        $threshold = PointThreshold::create([
            'school_unit_id' => $unit?->id,
            'min_points' => $validated['min_points'],
            'max_points' => $validated['max_points'],
            'label' => $validated['label'],
            'action' => $validated['action'] ?? null,
            'color' => $validated['color'] ?? null,
            'notify_guardian' => $validated['notify_guardian'] ?? true,
        ]);

        ActivityLog::record($request->user(), 'point_threshold.created', $threshold, ['label' => $threshold->label]);

        return response()->json(['threshold' => $threshold], 201);
    }

    public function update(Request $request, PointThreshold $pointThreshold): JsonResponse
    {
        abort_if(
            $request->user()->isUnitScoped() && $pointThreshold->school_unit_id !== $request->user()->school_unit_id,
            404,
        );

        $validated = $request->validate([
            'min_points' => 'sometimes|integer',
            'max_points' => 'sometimes|integer',
            'label' => 'sometimes|string|max:80',
            'action' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:20',
            'notify_guardian' => 'boolean',
        ]);

        $pointThreshold->update($validated);

        ActivityLog::record($request->user(), 'point_threshold.updated', $pointThreshold, $validated);

        return response()->json(['threshold' => $pointThreshold->fresh()]);
    }
}
