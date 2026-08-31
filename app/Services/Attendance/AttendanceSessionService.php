<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Opens and closes the "roll call windows" a teacher runs one lesson period
 * at a time. A session's token is the only credential the public check-in
 * endpoints require - see attendance_sessions migration for why that is safe.
 */
class AttendanceSessionService
{
    /** Reuses an existing, still-open session for this schedule+date rather than minting a new token every time the teacher reopens the page. */
    public function open(ClassSchedule $schedule, Carbon $date, User $openedBy): AttendanceSession
    {
        $existing = AttendanceSession::where('class_schedule_id', $schedule->id)
            ->whereDate('occurred_on', $date->toDateString())
            ->where('status', 'open')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            return $existing;
        }

        $expiresAt = $date->copy()->setTimeFromTimeString($schedule->end_time);

        return AttendanceSession::create([
            'class_schedule_id' => $schedule->id,
            'occurred_on' => $date->toDateString(),
            'token' => Str::random(48),
            'status' => 'open',
            'opened_by' => $openedBy->id,
            'opened_at' => now(),
            'expires_at' => $expiresAt,
        ]);
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
