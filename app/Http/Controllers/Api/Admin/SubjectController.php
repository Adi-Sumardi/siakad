<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectRequest;
use App\Http\Requests\Admin\UpdateSubjectRequest;
use App\Models\ActivityLog;
use App\Models\ClassSchedule;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\SubjectTingkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The subject catalogue schedules draw from - same central/unit split as
 * PointRuleController. One row per subject name, with the grade levels it
 * runs in (subject_tingkat). A subject that has been used (in a schedule,
 * or by a grade) is never deleted, only deactivated: both FKs are
 * restrictOnDelete and the history keeps pointing at the row.
 */
class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subjects = Subject::query()
            ->with('schoolUnit', 'tingkatRows')
            ->withCount('classSchedules')
            ->notMerged()
            ->when($request->user()->isUnitScoped(), fn ($q) => $q->forUnit($request->user()->school_unit_id))
            // Deactivated subjects stay out of every picker by default; the
            // catalogue card on the schedule page opts in so a row can be
            // re-activated or cleaned up.
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        // Schedules per subject per tingkat, so the UI can warn before a
        // tingkat that is still timetabled gets switched off.
        $usage = ClassSchedule::query()
            ->join('classrooms', 'classrooms.id', '=', 'class_schedules.classroom_id')
            ->whereIn('class_schedules.subject_id', $subjects->pluck('id'))
            ->select('class_schedules.subject_id', 'classrooms.tingkat', DB::raw('count(*) as n'))
            ->groupBy('class_schedules.subject_id', 'classrooms.tingkat')
            ->get()
            ->groupBy('subject_id');

        return response()->json([
            'subjects' => $subjects->map(fn (Subject $s) => [
                'ulid' => $s->ulid,
                'school_unit' => $s->schoolUnit?->label,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'schedules_count' => $s->class_schedules_count,
                'tingkat' => $s->tingkatRows->map(fn (SubjectTingkat $t) => [
                    'tingkat' => $t->tingkat,
                    'is_active' => $t->is_active,
                    'schedules_count' => (int) ($usage->get($s->id)?->firstWhere('tingkat', $t->tingkat)?->n ?? 0),
                ])->values(),
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

        // One row per name within a scope: a second "Bahasa Indonesia" for
        // another tingkat is exactly the duplication this catalogue replaced.
        // Includes deactivated rows - those must be re-activated instead.
        if ($existing = $this->sameName($unit?->id, $validated['name'])) {
            return response()->json([
                'message' => "Mata pelajaran {$existing->name} sudah ada. Tambahkan tingkatnya lewat tombol Edit.",
            ], 422);
        }

        if (($validated['code'] ?? null) && Subject::where('school_unit_id', $unit?->id)->where('code', $validated['code'])->exists()) {
            return response()->json(['message' => 'Kode mata pelajaran ini sudah dipakai untuk cakupan yang sama.'], 422);
        }

        $subject = DB::transaction(function () use ($unit, $validated) {
            $subject = Subject::create([
                'school_unit_id' => $unit?->id,
                'code' => $validated['code'] ?? null,
                'name' => trim($validated['name']),
            ]);
            $this->syncTingkat($subject, $validated['tingkat']);

            return $subject;
        });

        ActivityLog::record($request->user(), 'subject.created', $subject, [
            'name' => $subject->name, 'tingkat' => $validated['tingkat'],
        ]);

        return response()->json(['subject' => $subject->load('tingkatRows')], 201);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $this->authoriseScope($request, $subject);

        $validated = $request->validated();

        if (isset($validated['name'])) {
            $other = $this->sameName($subject->school_unit_id, $validated['name']);
            if ($other && $other->id !== $subject->id) {
                return response()->json(['message' => "Mata pelajaran {$other->name} sudah ada."], 422);
            }
        }

        DB::transaction(function () use ($subject, $validated) {
            $subject->update(collect($validated)->only(['name', 'is_active'])->all());

            if (isset($validated['tingkat'])) {
                $this->syncTingkat($subject, $validated['tingkat']);
            }
        });

        ActivityLog::record($request->user(), 'subject.updated', $subject, $validated);

        return response()->json(['subject' => $subject->fresh(['schoolUnit', 'tingkatRows'])]);
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

        ActivityLog::record($request->user(), 'subject.deleted', $subject, ['name' => $subject->name]);
        $subject->delete();

        return response()->json(['message' => 'Mata pelajaran dihapus.']);
    }

    private function sameName(?int $unitId, string $name): ?Subject
    {
        return Subject::query()
            ->notMerged()
            ->where('school_unit_id', $unitId)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->first();
    }

    /**
     * Makes `$tingkat` the subject's active grade levels. A tingkat dropped
     * from the list is switched off rather than deleted while a schedule in
     * that tingkat still uses the subject, so the timetable keeps its history.
     *
     * @param  list<int>  $tingkat
     */
    private function syncTingkat(Subject $subject, array $tingkat): void
    {
        $wanted = array_map('intval', $tingkat);

        foreach ($subject->tingkatRows()->get() as $row) {
            if (in_array($row->tingkat, $wanted, true)) {
                continue;
            }

            $used = $subject->classSchedules()->whereHas('classroom', fn ($q) => $q->where('tingkat', $row->tingkat))->exists();
            $used ? $row->update(['is_active' => false]) : $row->delete();
        }

        foreach ($wanted as $t) {
            SubjectTingkat::updateOrCreate(['subject_id' => $subject->id, 'tingkat' => $t], ['is_active' => true]);
        }
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
