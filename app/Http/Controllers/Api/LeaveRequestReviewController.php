<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Services\Attendance\LeaveRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff review of guardians' izin/sakit notices (audit 6 Okt 2026 #3), one
 * controller for both lanes because only the scope differs: a central admin
 * sees every unit, a unit admin (TU) their own unit, and a homeroom teacher
 * only the students sitting in their own rooms this year. Out-of-scope rows
 * are 404, never 403 (R3).
 */
class LeaveRequestReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $requests = $this->scoped($request)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['student', 'requester', 'reviewer'])
            ->orderBy($status === 'pending' ? 'date_from' : 'updated_at', $status === 'pending' ? 'asc' : 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'leave_requests' => LeaveRequestResource::collection($requests),
            'pending_count' => $this->scoped($request)->where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, string $ulid, LeaveRequestService $service): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        $leave = $this->scoped($request)->where('ulid', $ulid)->firstOrFail();

        try {
            $marked = $service->approve($leave, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'approved', 'windows_marked' => $marked]);
    }

    public function reject(Request $request, string $ulid, LeaveRequestService $service): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|min:3|max:500'], [
            'note.required' => 'Tuliskan alasan penolakan agar wali murid tahu.',
        ]);
        $leave = $this->scoped($request)->where('ulid', $ulid)->firstOrFail();

        try {
            $service->reject($leave, $request->user(), $data['note']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'rejected']);
    }

    /** The attached surat dokter / photo. Guardians reach their own via the same scope rule in scoped(). */
    public function attachment(Request $request, string $ulid): StreamedResponse
    {
        $leave = $this->scoped($request)->where('ulid', $ulid)->firstOrFail();

        abort_if(! $leave->attachment_path || ! Storage::disk('local')->exists($leave->attachment_path), 404);

        return Storage::disk('local')->response($leave->attachment_path, $leave->attachment_name);
    }

    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $query = LeaveRequest::query();

        if ($user->isGuardian()) {
            return $query->whereIn('student_id', \App\Models\Student::visibleTo($user)->select('id'));
        }

        if ($user->role === 'guru') {
            $yearId = AcademicYear::current()?->id;
            $rooms = Classroom::query()
                ->where('homeroom_teacher_id', $user->id)
                ->where('school_unit_id', $user->school_unit_id)
                ->where('is_active', true)
                ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
                ->pluck('id');

            return $query->whereIn('student_id', Enrollment::query()
                ->whereIn('classroom_id', $rooms)
                ->where('status', 'active')
                ->select('student_id'));
        }

        if ($user->isUnitScoped()) {
            return $query->where('school_unit_id', $user->school_unit_id);
        }

        return $query;
    }
}
