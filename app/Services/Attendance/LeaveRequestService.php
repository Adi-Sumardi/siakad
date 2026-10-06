<?php

namespace App\Services\Attendance;

use App\Models\ActivityLog;
use App\Models\DailyRecord;
use App\Models\DailySession;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The review half of a guardian's izin/sakit notice (audit 6 Okt 2026 #3).
 *
 * Approval writes through DailyAttendanceService::mark() - the one sanctioned
 * write path - for every covered masuk window that already exists (past days
 * and today). Days still ahead have no window yet; closeAndSweep() reads the
 * approved notice at close-time and writes sakit/izin instead of alpa.
 *
 * A live HADIR is never overwritten: if the child actually came in, the
 * record of them coming in is the truth, whatever the form said.
 */
class LeaveRequestService
{
    public function __construct(private DailyAttendanceService $daily) {}

    /** @return int how many existing windows were marked */
    public function approve(LeaveRequest $leave, User $reviewer, ?string $note = null): int
    {
        $leave = $this->claimPending($leave, $reviewer, 'approved', $note);

        $student = $leave->student;
        $source = $reviewer->role === 'guru' ? 'wali_kelas' : 'tu';
        $label = $leave->type === 'sakit' ? 'Sakit' : 'Izin';
        $description = mb_substr("{$label} (pengajuan wali disetujui): {$leave->reason}", 0, 500);
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $sessions = DailySession::query()
            ->where('school_unit_id', $student->school_unit_id)
            ->where('type', 'masuk')
            ->whereDate('date', '>=', $leave->date_from->toDateString())
            ->whereDate('date', '<=', min($leave->date_to->toDateString(), $today))
            ->get();

        $marked = 0;

        foreach ($sessions as $session) {
            $live = DailyRecord::where('daily_session_id', $session->id)
                ->where('student_id', $student->id)
                ->active()
                ->first();

            if ($live?->attendance_status === 'hadir') {
                continue;
            }

            $this->daily->mark($session, $student, $leave->type, $reviewer, $source, $description);
            $marked++;
        }

        ActivityLog::record($reviewer, 'leave_request.approved', $leave, [
            'student' => $student->nama_lengkap,
            'type' => $leave->type,
            'from' => $leave->date_from->toDateString(),
            'to' => $leave->date_to->toDateString(),
            'windows_marked' => $marked,
        ]);

        return $marked;
    }

    public function reject(LeaveRequest $leave, User $reviewer, string $note): void
    {
        $leave = $this->claimPending($leave, $reviewer, 'rejected', $note);

        ActivityLog::record($reviewer, 'leave_request.rejected', $leave, [
            'student' => $leave->student?->nama_lengkap,
            'note' => $note,
        ]);
    }

    public function cancel(LeaveRequest $leave, User $by): void
    {
        $this->claimPending($leave, $by, 'cancelled', null, recordReviewer: false);
    }

    /**
     * Flips a pending notice under a row lock so a double-tap (or a guru and
     * TU reviewing the same notice in the same second) decides exactly once.
     */
    private function claimPending(LeaveRequest $leave, User $by, string $status, ?string $note, bool $recordReviewer = true): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $by, $status, $note, $recordReviewer) {
            $fresh = LeaveRequest::whereKey($leave->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== 'pending') {
                throw new RuntimeException('Pengajuan ini sudah diproses sebelumnya.');
            }

            $fresh->forceFill(array_merge(
                ['status' => $status],
                $recordReviewer ? [
                    'reviewed_by' => $by->id,
                    'reviewed_at' => now(),
                    'review_note' => $note,
                ] : [],
            ))->save();

            return $fresh->load('student');
        });
    }
}
