<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The subject catalogue schedules draw from - same central/unit split as PointRuleController. */
class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Subject::query()
            ->with('schoolUnit')
            ->active()
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->forUnit($request->user()->school_unit_id))
            ->orderBy('name')
            ->get();

        return response()->json([
            'subjects' => $subjects->map(fn (Subject $s) => [
                'ulid' => $s->ulid,
                'school_unit' => $s->schoolUnit?->label,
                'code' => $s->code,
                'name' => $s->name,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'school_unit_code' => 'nullable|exists:school_units,code',
            'code' => 'required|string|max:32|alpha_dash',
            'name' => 'required|string|max:120',
        ]);

        $unit = $request->user()->isUnitScoped()
            ? $request->user()->schoolUnit
            : ($validated['school_unit_code'] ?? null ? SchoolUnit::findByCode($validated['school_unit_code']) : null);

        if ($request->user()->isUnitScoped() && ! $unit) {
            return response()->json(['message' => 'Mata pelajaran wajib untuk unit Anda sendiri.'], 422);
        }

        $clash = Subject::where('school_unit_id', $unit?->id)->where('code', $validated['code'])->exists();

        if ($clash) {
            return response()->json(['message' => 'Kode mata pelajaran ini sudah dipakai untuk cakupan yang sama.'], 422);
        }

        $subject = Subject::create([
            'school_unit_id' => $unit?->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
        ]);

        ActivityLog::record($request->user(), 'subject.created', $subject, ['code' => $subject->code]);

        return response()->json(['subject' => $subject], 201);
    }
}
