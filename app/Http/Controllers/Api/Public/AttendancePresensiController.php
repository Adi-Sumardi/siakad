<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Attendance\AttendanceLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reached without a Sanctum session - students have no account in this app.
 * A session's `token` is the only credential these endpoints require, the
 * same unauthenticated-but-token-gated shape as InvitationController. Never
 * return a classroom's student list here: every lookup is one NIS in, one
 * name out, so a stray token can't be used to browse a roster.
 */
class AttendancePresensiController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $session = AttendanceSession::where('token', $token)->firstOrFail();

        if (! $session->isOpen()) {
            return response()->json(['message' => 'Sesi presensi ini sudah ditutup.'], 410);
        }

        $schedule = $session->classSchedule;

        return response()->json([
            'subject' => $schedule->subject->name,
            'classroom' => $schedule->classroom->name,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
        ]);
    }

    /** Resolves a NIS to a name for confirmation - writes nothing. */
    public function lookup(Request $request, string $token, AttendanceLedger $ledger): JsonResponse
    {
        $validated = $request->validate(['nis' => 'required|string|max:50']);

        $session = AttendanceSession::where('token', $token)->firstOrFail();

        if (! $session->isOpen()) {
            return response()->json(['message' => 'Sesi presensi ini sudah ditutup.'], 410);
        }

        $student = $this->resolveStudent($session, $validated['nis']);

        if (! $student) {
            return response()->json(['message' => 'NIS tidak ditemukan di kelas ini.'], 404);
        }

        return response()->json([
            'student' => ['nama_panggilan' => $student->nama_panggilan],
            'already_checked_in' => $ledger->hasCheckedIn($session, $student),
        ]);
    }

    /** The actual write - re-validates everything server-side rather than trusting the client's lookup() result. */
    public function checkIn(Request $request, string $token, AttendanceLedger $ledger): JsonResponse
    {
        $validated = $request->validate(['nis' => 'required|string|max:50']);

        $session = AttendanceSession::where('token', $token)->firstOrFail();

        if (! $session->isOpen()) {
            return response()->json(['message' => 'Sesi presensi ini sudah ditutup.'], 410);
        }

        $student = $this->resolveStudent($session, $validated['nis']);

        if (! $student) {
            return response()->json(['message' => 'NIS tidak ditemukan di kelas ini.'], 404);
        }

        if ($ledger->hasCheckedIn($session, $student)) {
            return response()->json(['message' => 'Sudah tercatat hadir sebelumnya.'], 409);
        }

        $ledger->checkIn($session, $student);

        return response()->json(['status' => 'ok', 'student' => ['nama_panggilan' => $student->nama_panggilan]]);
    }

    /** A NIS only resolves within the schedule's own classroom - no user session to scope by, so the classroom itself is the boundary. */
    private function resolveStudent(AttendanceSession $session, string $nis): ?Student
    {
        $classroomId = $session->classSchedule->classroom_id;

        $enrollment = Enrollment::where('classroom_id', $classroomId)
            ->where('status', 'active')
            ->whereHas('student', fn ($q) => $q->where('nis', $nis))
            ->with('student')
            ->first();

        return $enrollment?->student;
    }
}
