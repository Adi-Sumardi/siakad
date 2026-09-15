<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\CompleteAttendanceSessionRequest;
use App\Http\Requests\Guru\RevokeReasonRequest;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceSessionService;
use App\Services\Attendance\RotatingQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class AttendanceSessionController extends Controller
{
    /** Opens (or reuses) today's roll-call window for one lesson period, and hands back the QR check-in link. */
    public function open(Request $request, string $scheduleUlid, AttendanceSessionService $sessions): JsonResponse
    {
        // Unit-visibility isn't enough here - ClassSchedule::visibleTo() lets
        // any teacher in the unit see every schedule, but only the teacher
        // actually assigned to this (classroom, subject) pair may open roll
        // call for it, matching how grading is already restricted.
        $schedule = ClassSchedule::visibleTo($request->user())
            ->where('teacher_id', $request->user()->id)
            ->where('ulid', $scheduleUlid)
            ->firstOrFail();

        // config('app.timezone') is UTC, not the Asia/Jakarta .env sets it to
        // (config/app.php never reads the env var) - a bare Carbon::today()
        // dates the session to the previous calendar day for the seven hours
        // every morning (00:00-07:00 WIB) that fall on UTC's previous day,
        // exactly when a teacher opens roll call for an early first period.
        $session = $sessions->open($schedule, Carbon::today('Asia/Jakarta'), $request->user());

        ActivityLog::record($request->user(), 'attendance.session_opened', $session, [
            'classroom' => $schedule->classroom->name, 'subject' => $schedule->subject->name,
        ]);

        return response()->json([
            'session' => [
                'ulid' => $session->ulid,
                'token' => $session->token,
                'expires_at' => $session->expires_at,
            ],
            'checkin_path' => $this->checkinPath($session),
        ]);
    }

    /**
     * The roll-call screen's rotating code - the same HMAC window scheme the
     * gate uses (RotatingQrService), scoped to this lesson session. A code
     * scanned anywhere the teacher's screen is NOT visible is dead within a
     * minute, so the session's static check-in URL stops being a shareable
     * "absen dari kantin" credential: the URL gets you to the page, the
     * rotating code gets you counted.
     */
    public function rotatingQr(Request $request, string $sessionUlid, RotatingQrService $qr): JsonResponse
    {
        $session = $this->ownSession($request, $sessionUlid);

        if (! $session->isOpen()) {
            return response()->json(['message' => 'Sesi presensi ini sudah ditutup.'], 410);
        }

        return response()->json([
            'code' => $qr->code(RotatingQrService::lessonScope($session->ulid)),
            'rotates_in' => $qr->secondsUntilRotation(),
            'window_seconds' => RotatingQrService::WINDOW_SECONDS,
        ]);
    }

    /** Live roster for the session's own panel - who has checked in, when, and how. */
    public function roster(Request $request, string $sessionUlid, AttendanceSessionService $sessions): JsonResponse
    {
        $session = $this->ownSession($request, $sessionUlid);

        return response()->json([
            'session' => [
                'ulid' => $session->ulid,
                'is_open' => $session->isOpen(),
                'expires_at' => $session->expires_at,
            ],
            // Safe to hand back here even though the token is a public
            // check-in credential: this endpoint is already gated by
            // ClassSchedule::visibleTo(), so only a teacher already
            // authorized for this classroom sees it - the QR still needs to
            // survive a page reload without re-opening the session.
            'checkin_path' => $this->checkinPath($session),
            'students' => $sessions->roster($session),
        ]);
    }

    /** A teacher striking one self-service check-in they believe is wrong (someone else's NIS, or a no-show). */
    public function revoke(RevokeReasonRequest $request, string $sessionUlid, string $recordUlid, AttendanceLedger $ledger): JsonResponse
    {
        $validated = $request->validated();

        $session = $this->ownSession($request, $sessionUlid);

        $record = AttendanceRecord::where('attendance_session_id', $session->id)
            ->where('ulid', $recordUlid)->firstOrFail();

        try {
            $ledger->revoke($record, $request->user(), $validated['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'attendance.session_record_revoked', $record, ['reason' => $validated['reason']]);

        return response()->json(['status' => 'ok']);
    }

    /** Marks the rest of the roster (sick/permitted/unexcused, or a teacher-witnessed "hadir") and closes the session. */
    public function complete(CompleteAttendanceSessionRequest $request, string $sessionUlid, AttendanceLedger $ledger, AttendanceSessionService $sessions): JsonResponse
    {
        $validated = $request->validated();

        $session = $this->ownSession($request, $sessionUlid);

        $entries = collect($validated['records'] ?? []);

        if ($entries->isNotEmpty()) {
            $ulids = $entries->pluck('student_ulid');

            // Unit-visibility isn't the right scope for who can be marked in
            // THIS session - it must be the classroom this schedule actually
            // teaches, or a teacher could mark any student in the unit
            // present/absent for a lesson period they were never part of.
            $classroomId = $session->classSchedule->classroom_id;
            $students = \App\Models\Student::whereIn('ulid', $ulids)
                ->whereHas('enrollments', fn ($q) => $q->where('classroom_id', $classroomId)->where('status', 'active'))
                ->get()->keyBy('ulid');

            if ($students->count() !== $ulids->unique()->count()) {
                return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan bagian dari kelas ini.'], 422);
            }

            $mapped = $entries->map(fn ($r) => [
                'student' => $students[$r['student_ulid']],
                'status' => $r['status'],
                'description' => $r['description'] ?? null,
            ]);

            try {
                $ledger->recordBulk($mapped, $session, $request->user());
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $sessions->close($session);

        ActivityLog::record($request->user(), 'attendance.session_completed', $session, [
            'manual_records' => $entries->count(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Unit-visibility on the classroom isn't enough - only the teacher
     * actually assigned to this schedule may act on its sessions, matching
     * how grading is already restricted (GuruGradeController::canGrade()).
     */
    private function ownSession(Request $request, string $sessionUlid): AttendanceSession
    {
        return AttendanceSession::whereHas(
            'classSchedule',
            fn ($q) => $q->visibleTo($request->user())->where('teacher_id', $request->user()->id)
        )->where('ulid', $sessionUlid)->firstOrFail();
    }

    /**
     * Path-only on purpose: the server cannot know which origin reaches the
     * frontend for a given caller (dev proxy, ngrok tunnel, production domain
     * all differ), and an absolute URL baked from app.frontend_url produced
     * dead QR codes in the 2026-09-15 tunnel test. Callers rebase this path
     * onto their own origin - only they know it.
     */
    private function checkinPath(AttendanceSession $session): string
    {
        return '/presensi/'.$session->token;
    }
}
