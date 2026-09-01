<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens and closes the "roll call windows" a teacher runs one lesson period
 * at a time. A session's token is the only credential the public check-in
 * endpoints require - see attendance_sessions migration for why that is safe.
 */
class AttendanceSessionService
{
    /**
     * At most one session ever exists for a (schedule, day) pair - enforced
     * by a DB unique constraint, not just this check. A closed or expired
     * session is reopened in place rather than left behind for a fresh one
     * to be created alongside it - two independent sessions for the same
     * lesson period used to mean a student who'd already checked into the
     * first could get marked 'alpa' on the second with no reconciliation.
     */
    public function open(ClassSchedule $schedule, Carbon $date, User $openedBy): AttendanceSession
    {
        $existing = AttendanceSession::where('class_schedule_id', $schedule->id)
            ->whereDate('occurred_on', $date->toDateString())
            ->first();

        if ($existing) {
            if ($existing->status !== 'open' || $existing->expires_at <= now()) {
                $existing->forceFill([
                    'status' => 'open',
                    'closed_at' => null,
                    'expires_at' => $date->copy()->setTimeFromTimeString($schedule->end_time),
                ])->save();
            }

            return $existing;
        }

        try {
            return AttendanceSession::create([
                'class_schedule_id' => $schedule->id,
                'occurred_on' => $date->toDateString(),
                'token' => Str::random(48),
                'status' => 'open',
                'opened_by' => $openedBy->id,
                'opened_at' => now(),
                'expires_at' => $date->copy()->setTimeFromTimeString($schedule->end_time),
            ]);
        } catch (QueryException $e) {
            // Lost the race to another request opening the same (schedule,
            // day) between our check above and this insert - the unique
            // constraint caught what the check couldn't. Whoever won is the
            // session to use.
            $winner = AttendanceSession::where('class_schedule_id', $schedule->id)
                ->whereDate('occurred_on', $date->toDateString())
                ->first();

            if (! $winner) {
                throw $e;
            }

            return $winner;
        }
    }

    /** Closes the session and syncs the enrollment rollup once for every student touched in it - self check-ins never sync per-scan, so this is where that catches up. */
    public function close(AttendanceSession $session, AttendanceLedger $ledger): void
    {
        $session->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        $students = Student::whereIn('id', AttendanceRecord::where('attendance_session_id', $session->id)
            ->active()
            ->pluck('student_id')
            ->unique())->get();

        foreach ($students as $student) {
            $ledger->syncEnrollmentRollup($student);
        }
    }

    /** Roster for the guru panel: every active student in the schedule's classroom, plus who has checked in. */
    public function roster(AttendanceSession $session): Collection
    {
        $classroom = $session->classSchedule->classroom;

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        $checkedIn = AttendanceRecord::where('attendance_session_id', $session->id)
            ->active()
            ->get()
            ->keyBy('student_id');

        return $students->map(function ($student) use ($checkedIn) {
            $record = $checkedIn->get($student->id);

            return [
                'ulid' => $student->ulid,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
                'record_ulid' => $record?->ulid,
                'attendance_status' => $record?->attendance_status,
                'source' => $record?->source,
                'checked_in_at' => $record?->created_at,
            ];
        });
    }
}
