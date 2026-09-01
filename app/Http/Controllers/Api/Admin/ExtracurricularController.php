<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\ExtracurricularService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExtracurricularController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = Extracurricular::query()
            ->visibleTo($request->user())
            ->with(['schoolUnit', 'academicYear', 'pembina'])
            ->withCount(['activeMembers as member_count'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'extracurriculars' => $activities->map(fn (Extracurricular $e) => $this->shape($e)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:1000',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'academic_year_ulid' => 'required|string',
            'pembina_ulid' => 'nullable|string',
            'capacity' => 'nullable|integer|min:1|max:500',
        ]);

        $unit = $this->resolveUnit($request, $validated['school_unit_code'] ?? null);
        $academicYear = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $pembina = isset($validated['pembina_ulid'])
            ? User::where('ulid', $validated['pembina_ulid'])->where('role', 'guru')->first()
            : null;

        $ekskul = Extracurricular::create([
            'school_unit_id' => $unit?->id,
            'academic_year_id' => $academicYear->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'pembina_id' => $pembina?->id,
            'capacity' => $validated['capacity'] ?? null,
            'is_active' => true,
        ]);

        ActivityLog::record($request->user(), 'extracurricular.created', $ekskul, ['name' => $ekskul->name]);

        return response()->json(['extracurricular' => $this->shape($ekskul->fresh(['schoolUnit', 'academicYear', 'pembina']))], 201);
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        $ekskul = Extracurricular::where('ulid', $ulid)->firstOrFail();
        $this->authoriseScope($request, $ekskul);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:80',
            'description' => 'nullable|string|max:1000',
            'pembina_ulid' => 'nullable|string',
            'capacity' => 'nullable|integer|min:1|max:500',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('pembina_ulid', $validated)) {
            $pembina = $validated['pembina_ulid']
                ? User::where('ulid', $validated['pembina_ulid'])->where('role', 'guru')->first()
                : null;
            $ekskul->pembina_id = $pembina?->id;
        }

        $ekskul->fill(collect($validated)->only(['name', 'description', 'capacity', 'is_active'])->all());
        $ekskul->save();

        ActivityLog::record($request->user(), 'extracurricular.updated', $ekskul, $validated);

        return response()->json(['extracurricular' => $this->shape($ekskul->fresh(['schoolUnit', 'academicYear', 'pembina']))]);
    }

    public function roster(Request $request, string $ulid): JsonResponse
    {
        $ekskul = Extracurricular::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $members = $ekskul->activeMembers()->with('student')->get();

        return response()->json([
            'extracurricular' => ['ulid' => $ekskul->ulid, 'name' => $ekskul->name],
            'members' => $members->map(fn (ExtracurricularMember $m) => [
                'ulid' => $m->ulid,
                'student' => ['ulid' => $m->student->ulid, 'nama_lengkap' => $m->student->nama_lengkap, 'nis' => $m->student->nis],
                'joined_on' => $m->joined_on?->toDateString(),
            ]),
        ]);
    }

    public function assignStudent(Request $request, string $ulid, ExtracurricularService $service): JsonResponse
    {
        $ekskul = Extracurricular::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validate(['student_ulid' => 'required|string']);
        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

        try {
            $member = $service->assignStudent($ekskul, $student, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'extracurricular.member_assigned', $ekskul, ['student' => $student->nama_lengkap]);

        return response()->json(['member' => ['ulid' => $member->ulid]], 201);
    }

    public function removeMember(Request $request, string $ulid, string $memberUlid, ExtracurricularService $service): JsonResponse
    {
        $ekskul = Extracurricular::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $member = $ekskul->members()->where('ulid', $memberUlid)->firstOrFail();

        try {
            $service->removeStudent($member);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'extracurricular.member_removed', $ekskul, ['member' => $member->ulid]);

        return response()->json(['message' => 'Anggota dikeluarkan dari ekstrakurikuler.']);
    }

    private function shape(Extracurricular $e): array
    {
        return [
            'ulid' => $e->ulid,
            'name' => $e->name,
            'description' => $e->description,
            'school_unit' => $e->schoolUnit ? ['code' => $e->schoolUnit->code, 'label' => $e->schoolUnit->label] : null,
            'academic_year' => $e->academicYear?->year,
            'pembina' => $e->pembina?->name,
            'capacity' => $e->capacity,
            'member_count' => $e->member_count ?? $e->activeMembers()->count(),
            'is_active' => $e->is_active,
        ];
    }

    private function resolveUnit(Request $request, ?string $code): ?SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            return $request->user()->schoolUnit;
        }

        return $code ? SchoolUnit::findByCode($code) : null;
    }

    private function authoriseScope(Request $request, Extracurricular $ekskul): void
    {
        $user = $request->user();

        abort_if(
            $user->isUnitScoped() && $ekskul->school_unit_id !== $user->school_unit_id,
            404,
        );
    }
}
