<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\EnrollExtracurricularRequest;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Extracurricular;
use App\Models\Student;
use App\Services\Academic\ExtracurricularService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExtracurricularController extends Controller
{
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $activities = Extracurricular::query()
            ->whereHas('activeMembers', fn ($q) => $q->where('student_id', $student->id))
            ->with(['schoolUnit', 'pembina'])
            ->get();

        return response()->json([
            'extracurriculars' => $activities->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid,
                'name' => $e->name,
                'pembina' => $e->pembina?->name,
                'school_unit' => $e->schoolUnit?->label,
            ]),

            // What the parent may still pick from: this academic year's
            // active activities, in the child's unit (or school-wide), with
            // room left, that the child has not joined yet.
            'available' => $this->availableFor($student, $activities->pluck('id'))->map(fn (Extracurricular $e) => [
                'ulid' => $e->ulid,
                'name' => $e->name,
                'pembina' => $e->pembina?->name,
                'member_count' => $e->member_count,
                'capacity' => $e->capacity,
            ]),
        ]);
    }

    /**
     * Self-service enrolment (decision 2026-09-09: parents may register
     * their own children). The service runs the exact same rules an admin
     * assign goes through - unit match, no double-enrolment, capacity, row
     * lock - so the only gates added here are the ones a parent faces:
     * the activity must be active and belong to the running academic year.
     */
    public function enroll(EnrollExtracurricularRequest $request, string $ulid, ExtracurricularService $service): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validated();

        $ekskul = Extracurricular::where('ulid', $validated['extracurricular_ulid'])->first();
        $year = AcademicYear::where('is_active', true)->first();

        if (! $ekskul || ! $ekskul->is_active || ! $year || $ekskul->academic_year_id !== $year->id) {
            return response()->json(['message' => 'Ekstrakurikuler tidak tersedia untuk didaftarkan.'], 422);
        }

        try {
            $member = $service->assignStudent($ekskul, $student, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'extracurricular.self_enrolled', $ekskul, [
            'student' => $student->nama_lengkap,
        ]);

        return response()->json([
            'message' => "Ananda terdaftar di {$ekskul->name}.",
            'member' => ['ulid' => $member->ulid],
        ], 201);
    }

    private function availableFor(Student $student, $joinedIds)
    {
        $year = AcademicYear::where('is_active', true)->first();

        if (! $year) {
            return collect();
        }

        return Extracurricular::query()
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('school_unit_id')->orWhere('school_unit_id', $student->school_unit_id))
            ->with(['pembina'])
            ->withCount(['activeMembers as member_count'])
            ->get()
            ->reject(fn (Extracurricular $e) => $joinedIds->contains($e->id)
                || ($e->capacity !== null && $e->member_count >= $e->capacity))
            ->values();
    }
}
