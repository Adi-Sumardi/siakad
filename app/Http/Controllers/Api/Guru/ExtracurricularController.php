<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Student;
use App\Services\Academic\ExtracurricularService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A pembina manages only the activities they themselves supervise - not
 * every activity in their unit the way an admin does. Every query here is
 * scoped by pembina_id, deliberately not Extracurricular::visibleTo(),
 * which is a broader unit-wide scope built for the admin side.
 */
class ExtracurricularController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = Extracurricular::where('pembina_id', $request->user()->id)
            ->withCount(['activeMembers as member_count'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'extracurriculars' => $activities->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid, 'name' => $e->name, 'capacity' => $e->capacity, 'member_count' => $e->member_count,
            ]),
        ]);
    }

    public function roster(Request $request, string $ulid): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);

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
        $ekskul = $this->ownActivity($request, $ulid);

        $validated = $request->validate(['student_ulid' => 'required|string']);
        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

        try {
            $member = $service->assignStudent($ekskul, $student, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['member' => ['ulid' => $member->ulid]], 201);
    }

    public function removeMember(Request $request, string $ulid, string $memberUlid, ExtracurricularService $service): JsonResponse
    {
        $ekskul = $this->ownActivity($request, $ulid);
        $member = $ekskul->members()->where('ulid', $memberUlid)->firstOrFail();

        try {
            $service->removeStudent($member);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Anggota dikeluarkan dari ekstrakurikuler.']);
    }

    private function ownActivity(Request $request, string $ulid): Extracurricular
    {
        return Extracurricular::where('pembina_id', $request->user()->id)->where('ulid', $ulid)->firstOrFail();
    }
}
