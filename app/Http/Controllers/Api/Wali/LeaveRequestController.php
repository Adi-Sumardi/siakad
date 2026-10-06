<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\ActivityLog;
use App\Models\LeaveRequest;
use App\Models\Student;
use App\Services\Attendance\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The guardian's izin/sakit form (audit 6 Okt 2026 #3) - the lane that used
 * to be a phone call. Filing changes no attendance; the homeroom teacher or
 * TU approves, and only approval writes.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $requests = LeaveRequest::where('student_id', $student->id)
            ->with('reviewer')
            ->orderByDesc('date_from')
            ->limit(50)
            ->get();

        return response()->json([
            'student' => ['ulid' => $student->ulid, 'nama_lengkap' => $student->nama_lengkap],
            'leave_requests' => LeaveRequestResource::collection($requests),
        ]);
    }

    public function store(StoreLeaveRequestRequest $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $validated = $request->validated();

        $overlaps = LeaveRequest::where('student_id', $student->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('date_from', '<=', $validated['date_to'])
            ->whereDate('date_to', '>=', $validated['date_from'])
            ->exists();

        if ($overlaps) {
            return response()->json([
                'message' => 'Sudah ada pengajuan izin/sakit untuk anak ini di tanggal tersebut.',
            ], 422);
        }

        $leave = LeaveRequest::create([
            'student_id' => $student->id,
            'school_unit_id' => $student->school_unit_id,
            'requested_by' => $request->user()->id,
            'type' => $validated['type'],
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'reason' => $validated['reason'],
            'attachment_path' => $request->hasFile('attachment') ? $request->file('attachment')->store('leave-requests', 'local') : null,
            'attachment_name' => $request->file('attachment')?->getClientOriginalName(),
            'status' => 'pending',
        ]);

        ActivityLog::record($request->user(), 'leave_request.submitted', $leave, [
            'student' => $student->nama_lengkap,
            'type' => $leave->type,
        ]);

        return response()->json(['leave_request' => new LeaveRequestResource($leave)], 201);
    }

    public function cancel(Request $request, string $ulid, LeaveRequestService $service): JsonResponse
    {
        $leave = LeaveRequest::where('ulid', $ulid)
            ->whereIn('student_id', Student::visibleTo($request->user())->select('id'))
            ->firstOrFail();

        try {
            $service->cancel($leave, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok']);
    }
}
