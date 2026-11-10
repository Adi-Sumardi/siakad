<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentFace;
use App\Services\Attendance\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff side of gate check-in by face (docs/09-ABSEN-WAJAH-GERBANG.md):
 *
 * - the unit's public check-in link, readable by every staff role that
 *   shares it (admin pusat per unit, admin unit, waka, guru) - issuing and
 *   resetting the link stays with the admins;
 * - the registered reference face and each check-in's snapshot, for staff
 *   who can see the student (Student::visibleTo, guardians excluded);
 * - deleting a wrongly registered face so the next check-in enrolls again.
 *   A guru may do this only for their own homeroom students.
 */
class StudentFaceController extends Controller
{
    public function __construct(private FaceMatcher $faces) {}

    public function publicLink(Request $request): JsonResponse
    {
        $unit = $this->unitFor($request);
        $setting = DailyAttendanceSetting::where('school_unit_id', $unit->id)->first();
        $slug = $setting?->intake_mode === 'gerbang' && $setting->enabled ? $setting->public_slug : null;

        return response()->json([
            'unit' => ['ulid' => $unit->ulid, 'label' => $unit->label],
            'intake_mode' => $setting?->intake_mode,
            'enabled' => (bool) $setting?->enabled,
            'face_required' => (bool) $setting?->face_required,
            'public_path' => $slug ? '/absen/'.$slug : null,
            'public_url' => $slug ? DailyAttendanceSetting::publicUrlFor($slug) : null,
        ]);
    }

    /** Students of the unit with their face status - search by name or NIS. */
    public function index(Request $request): JsonResponse
    {
        $unit = $this->unitFor($request);
        $q = trim((string) $request->query('q', ''));

        $students = Student::visibleTo($request->user())
            ->active()
            ->where('school_unit_id', $unit->id)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('nama_lengkap', 'like', "%{$q}%")
                ->orWhere('nis', 'like', "%{$q}%")))
            ->when($request->query('status') === 'terdaftar', fn ($query) => $query->whereHas('face'))
            ->when($request->query('status') === 'belum', fn ($query) => $query->whereDoesntHave('face'))
            ->with('face')
            ->orderBy('nama_lengkap')
            ->paginate(30);

        return response()->json([
            'data' => collect($students->items())->map(fn (Student $s) => [
                'ulid' => $s->ulid,
                'nama_lengkap' => $s->nama_lengkap,
                'nis' => $s->nis,
                'classroom' => $s->currentEnrollment()?->classroom?->name,
                'face_enrolled_at' => $s->face?->enrolled_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'total' => $students->total(),
                'per_page' => $students->perPage(),
                'enrolled' => StudentFace::whereHas('student', fn ($s) => $s->active()->where('school_unit_id', $unit->id))->count(),
            ],
        ]);
    }

    public function photo(Request $request, string $ulid): StreamedResponse
    {
        $student = $this->visibleStudent($request, $ulid);

        return $this->stream($student->face?->photo_path);
    }

    public function recordPhoto(Request $request, string $ulid): StreamedResponse
    {
        abort_if($request->user()->isGuardian(), 404);

        $record = DailyRecord::where('ulid', $ulid)->firstOrFail();
        abort_unless(Student::visibleTo($request->user())->whereKey($record->student_id)->exists(), 404);

        return $this->stream($record->photo_path);
    }

    public function destroy(Request $request, string $ulid): JsonResponse
    {
        $student = $this->visibleStudent($request, $ulid);

        if ($request->user()->role === 'guru' && ! $this->homeroomClassroomIds($request)->contains(
            $student->currentEnrollment()?->classroom_id
        )) {
            abort(404);
        }

        $face = $student->face ?: abort(404);

        $this->faces->deletePhoto($face->photo_path);
        $face->delete();

        ActivityLog::record($request->user(), 'student_face.reset', $student, [
            'student' => $student->nama_lengkap,
            'nis' => $student->nis,
        ]);

        return response()->json(['status' => 'ok']);
    }

    private function visibleStudent(Request $request, string $ulid): Student
    {
        abort_if($request->user()->isGuardian(), 404);

        return Student::visibleTo($request->user())->where('ulid', $ulid)->with('face')->firstOrFail();
    }

    private function unitFor(Request $request): SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            return $request->user()->schoolUnit ?: abort(404);
        }

        return SchoolUnit::where('ulid', $request->query('unit'))->firstOrFail();
    }

    private function homeroomClassroomIds(Request $request)
    {
        $yearId = AcademicYear::current()?->id;

        return Classroom::query()
            ->where('homeroom_teacher_id', $request->user()->id)
            ->where('school_unit_id', $request->user()->school_unit_id)
            ->where('is_active', true)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->pluck('id');
    }

    private function stream(?string $path): StreamedResponse
    {
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
