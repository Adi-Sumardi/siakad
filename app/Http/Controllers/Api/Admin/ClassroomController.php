<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Creates and edits classrooms - the one prerequisite kenaikan kelas massal
 * needed and never had. Read access stays on ReferenceController::classrooms(),
 * the picker every other admin screen already depends on; this controller
 * only writes.
 */
class ClassroomController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'tingkat' => 'required|integer|min:1|max:12',
            'school_unit_code' => 'nullable|exists:school_units,code',
            'academic_year_ulid' => 'required|string',
            'capacity' => 'nullable|integer|min:1|max:100',
            'homeroom_teacher_ulid' => 'nullable|string',
        ]);

        $unit = $this->resolveUnit($request, $validated['school_unit_code'] ?? null);

        if (! $unit) {
            // A central admin left the unit out - unlike a point rule, a
            // classroom always belongs to exactly one unit, there is no
            // school-wide classroom to fall back to.
            return response()->json(['message' => 'Unit sekolah wajib diisi.'], 422);
        }

        $academicYear = AcademicYear::where('ulid', $validated['academic_year_ulid'])->firstOrFail();

        $homeroomTeacher = isset($validated['homeroom_teacher_ulid'])
            ? User::where('ulid', $validated['homeroom_teacher_ulid'])->where('role', 'guru')->first()
            : null;

        $classroom = Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $academicYear->id,
            'tingkat' => $validated['tingkat'],
            'name' => $validated['name'],
            'homeroom_teacher_id' => $homeroomTeacher?->id,
            'capacity' => $validated['capacity'] ?? null,
            'is_active' => true,
        ]);

        ActivityLog::record($request->user(), 'classroom.created', $classroom, [
            'unit' => $unit->label, 'academic_year' => $academicYear->year, 'name' => $classroom->name,
        ]);

        return response()->json(['classroom' => $classroom->fresh('schoolUnit')], 201);
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::where('ulid', $ulid)->firstOrFail();
        $this->authoriseScope($request, $classroom);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:60',
            'tingkat' => 'sometimes|integer|min:1|max:12',
            'capacity' => 'nullable|integer|min:1|max:100',
            'homeroom_teacher_ulid' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('homeroom_teacher_ulid', $validated)) {
            $teacher = $validated['homeroom_teacher_ulid']
                ? User::where('ulid', $validated['homeroom_teacher_ulid'])->where('role', 'guru')->first()
                : null;
            $classroom->homeroom_teacher_id = $teacher?->id;
        }

        $classroom->fill(collect($validated)->only(['name', 'tingkat', 'capacity', 'is_active'])->all());
        $classroom->save();

        ActivityLog::record($request->user(), 'classroom.updated', $classroom, $validated);

        return response()->json(['classroom' => $classroom->fresh('schoolUnit')]);
    }

    /**
     * enrollments/announcements/class_schedules/attendance_records/grades
     * all cascade-delete off a classroom - unlike students or fee types,
     * nothing here is protected by the database's own foreign keys, so a
     * classroom still holding real enrollment, attendance, or grade history
     * would be silently wiped along with it. Refused at the application
     * layer instead: a classroom with zero enrollments ever (past or
     * present) is safe to remove outright, anything else needs to be
     * deactivated (is_active) rather than deleted.
     */
    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::where('ulid', $ulid)->firstOrFail();
        $this->authoriseScope($request, $classroom);

        if ($classroom->enrollments()->exists()) {
            return response()->json([
                'message' => "Kelas \"{$classroom->name}\" masih punya riwayat siswa (aktif atau lulus) - nonaktifkan saja kelas ini, jangan dihapus.",
            ], 422);
        }

        ActivityLog::record($request->user(), 'classroom.deleted', $classroom, [
            'unit' => $classroom->schoolUnit->label, 'name' => $classroom->name,
        ]);

        $classroom->delete();

        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }

    private function resolveUnit(Request $request, ?string $code): ?SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            return $request->user()->schoolUnit;
        }

        return $code ? SchoolUnit::findByCode($code) : null;
    }

    private function authoriseScope(Request $request, Classroom $classroom): void
    {
        $user = $request->user();

        abort_if(
            $user->isUnitScoped() && $classroom->school_unit_id !== $user->school_unit_id,
            404,
        );
    }
}
