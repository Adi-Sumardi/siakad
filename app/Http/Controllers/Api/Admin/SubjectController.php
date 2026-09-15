<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectRequest;
use App\Http\Requests\Admin\UpdateSubjectRequest;
use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The subject catalogue schedules draw from - same central/unit split as
 * PointRuleController. A subject that has been used (in a schedule, or by a
 * grade) is never deleted, only deactivated: both FKs are restrictOnDelete
 * and the history keeps pointing at the row.
 */
class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Subject::query()
            ->with('schoolUnit')
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->forUnit($request->user()->school_unit_id))
            // Deactivated subjects stay out of every picker by default; the
            // catalogue card on the schedule page opts in so a row can be
            // re-activated or cleaned up.
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return response()->json([
            'subjects' => $subjects->map(fn (Subject $s) => [
                'ulid' => $s->ulid,
                'school_unit' => $s->schoolUnit?->label,
                'code' => $s->code,
                'name' => $s->name,
                'is_active' => $s->is_active,
            ]),
        ]);
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $unit = $request->user()->isUnitScoped()
            ? $request->user()->schoolUnit
            : ($validated['school_unit_code'] ?? null ? SchoolUnit::findByCode($validated['school_unit_code']) : null);

        if ($request->user()->isUnitScoped() && ! $unit) {
            return response()->json(['message' => 'Mata pelajaran wajib untuk unit Anda sendiri.'], 422);
        }

        // Includes deactivated rows: a deactivated code must be re-activated,
        // not silently shadowed by a fresh row with the same key.
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

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $this->authoriseScope($request, $subject);

        $validated = $request->validated();

        $subject->update($validated);

        ActivityLog::record($request->user(), 'subject.updated', $subject, $validated);

        return response()->json(['subject' => $subject->fresh('schoolUnit')]);
    }

    public function destroy(Request $request, Subject $subject): JsonResponse
    {
        $this->authoriseScope($request, $subject);

        if ($subject->classSchedules()->exists()) {
            return response()->json([
                'message' => 'Mata pelajaran ini sudah terpakai di jadwal kelas. Nonaktifkan, bukan hapus.',
            ], 422);
        }

        if ($subject->grades()->exists()) {
            return response()->json([
                'message' => 'Mata pelajaran ini sudah punya nilai siswa. Nonaktifkan, bukan hapus.',
            ], 422);
        }

        ActivityLog::record($request->user(), 'subject.deleted', $subject, ['code' => $subject->code]);
        $subject->delete();

        return response()->json(['message' => 'Mata pelajaran dihapus.']);
    }

    private function authoriseScope(Request $request, Subject $subject): void
    {
        $user = $request->user();

        // School-wide subjects (null unit) fail this check for a unit admin
        // on purpose - they belong to the central admin only.
        abort_if(
            $user->isUnitScoped() && $subject->school_unit_id !== $user->school_unit_id,
            404,
        );
    }
}
