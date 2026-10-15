<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PointRecordResource;
use App\Models\ActivityLog;
use App\Models\Enrollment;
use App\Models\PointApproval;
use App\Models\PointRecord;
use App\Services\Points\PointApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The approval queue, one controller for every seat on the ladder: a guru
 * sees the wali kelas stage of their homeroom students, waka kesiswaan /
 * kepala sekolah (or the admin_unit sitting in for a vacant seat) see their
 * unit's stage. Who may decide what is PointApprovalService's call alone.
 */
class PointApprovalController extends Controller
{
    public function __construct(private PointApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $pending = $this->approvals->queueFor($user)
            ->with(['student', 'pointRule', 'recordedBy', 'approvals.decidedBy'])
            ->orderBy('occurred_on')->orderBy('id')
            ->limit(300)
            ->get();

        // Recently decided by this user - "what did I just approve?"
        $decided = PointRecord::query()
            ->whereHas('approvals', fn ($q) => $q->where('decided_by', $user->id)->where('created_at', '>=', now()->subDays(30)))
            ->with(['student', 'pointRule', 'recordedBy', 'approvals.decidedBy'])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'pending' => $this->present($pending),
            'decided' => $this->present($decided),
        ]);
    }

    /** Badge count for the nav - cheap, no relations. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['pending' => $this->approvals->queueFor($request->user())->count()]);
    }

    public function approve(Request $request, string $ulid): JsonResponse
    {
        $validated = $request->validate(['note' => 'nullable|string|max:500']);

        return $this->decide($request, $ulid, fn (PointRecord $r) => $this->approvals->approve($r, $request->user(), $validated['note'] ?? null), 'point.approved');
    }

    public function reject(Request $request, string $ulid): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:5|max:500'], [
            'reason.required' => 'Tuliskan alasan penolakan agar guru pencatat tahu.',
            'reason.min' => 'Alasan penolakan minimal 5 karakter.',
        ]);

        return $this->decide($request, $ulid, fn (PointRecord $r) => $this->approvals->reject($r, $request->user(), $validated['reason']), 'point.rejected');
    }

    /** Approve many at once - a whole bulk entry of late students is one decision. */
    public function approveMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ulids' => 'required|array|min:1|max:200',
            'ulids.*' => 'string',
        ]);

        $approved = 0;
        $skipped = 0;
        foreach (PointRecord::whereIn('ulid', $validated['ulids'])->get() as $record) {
            try {
                $this->approvals->approve($record, $request->user());
                $approved++;
            } catch (RuntimeException) {
                $skipped++;
            }
        }

        ActivityLog::record($request->user(), 'point.approved_bulk', null, ['approved' => $approved, 'skipped' => $skipped]);

        return response()->json(['approved' => $approved, 'skipped' => $skipped]);
    }

    private function decide(Request $request, string $ulid, \Closure $action, string $log): JsonResponse
    {
        $record = PointRecord::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        try {
            $record = $action($record);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), $log, $record, ['status' => $record->status, 'next' => $record->approval_stage]);

        return response()->json(['record' => new PointRecordResource($record->load(['pointRule', 'recordedBy', 'approvals.decidedBy']))]);
    }

    private function present($records): array
    {
        $classrooms = Enrollment::query()
            ->whereIn('student_id', $records->pluck('student_id'))
            ->where('status', 'active')
            ->with('classroom:id,name')
            ->get()
            ->mapWithKeys(fn ($e) => [$e->student_id => $e->classroom?->name]);

        return $records->map(fn (PointRecord $r) => [
            ...(new PointRecordResource($r))->resolve(),
            'nis' => $r->student?->nis,
            'classroom' => $classrooms[$r->student_id] ?? null,
            'stage_label' => $r->approval_stage ? PointApproval::STAGE_LABEL[$r->approval_stage] : null,
        ])->values()->all();
    }
}
