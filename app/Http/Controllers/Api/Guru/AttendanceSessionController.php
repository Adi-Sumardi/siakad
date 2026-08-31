<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class AttendanceSessionController extends Controller
{
    /** Opens (or reuses) today's roll-call window for one lesson period, and hands back the QR check-in link. */
    public function open(Request $request, string $scheduleUlid, AttendanceSessionService $sessions): JsonResponse
    {
        $schedule = ClassSchedule::visibleTo($request->user())->where('ulid', $scheduleUlid)->firstOrFail();

        $session = $sessions->open($schedule, Carbon::today(), $request->user());

        ActivityLog::record($request->user(), 'attendance.session_opened', $session, [
            'classroom' => $schedule->classroom->name, 'subject' => $schedule->subject->name,
        ]);

        return response()->json([
            'session' => [
                'ulid' => $session->ulid,
                'token' => $session->token,
                'expires_at' => $session->expires_at,
            ],
            'checkin_url' => rtrim((string) config('app.frontend_url'), '/').'/presensi/'.$session->token,
        ]);
    }

    /** Live roster for the session's own panel - who has checked in, when, and how. */
    public function roster(Request $request, string $sessionUlid, AttendanceSessionService $sessions): JsonResponse
    {
        $session = AttendanceSession::whereHas('classSchedule', fn ($q) => $q->visibleTo($request->user()))
            ->where('ulid', $sessionUlid)->firstOrFail();

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
            'checkin_url' => rtrim((string) config('app.frontend_url'), '/').'/presensi/'.$session->token,
            'students' => $sessions->roster($session),
        ]);
    }

    /** A teacher striking one self-service check-in they believe is wrong (someone else's NIS, or a no-show). */
    public function revoke(Request $request, string $sessionUlid, string $recordUlid, AttendanceLedger $ledger): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $session = AttendanceSession::whereHas('classSchedule', fn ($q) => $q->visibleTo($request->user()))
            ->where('ulid', $sessionUlid)->firstOrFail();

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
    public function complete(Request $request, string $sessionUlid, AttendanceLedger $ledger, AttendanceSessionService $sessions): JsonResponse
    {
        $validated = $request->validate([
            'records' => 'array|max:200',
            'records.*.student_ulid' => 'required_with:records|string',
            'records.*.status' => 'required_with:records|in:hadir,sakit,izin,alpa',
            'records.*.description' => 'nullable|string|max:500',
        ]);

        $session = AttendanceSession::whereHas('classSchedule', fn ($q) => $q->visibleTo($request->user()))
            ->where('ulid', $sessionUlid)->firstOrFail();

        $entries = collect($validated['records'] ?? []);

        if ($entries->isNotEmpty()) {
            $ulids = $entries->pluck('student_ulid');
            $students = \App\Models\Student::visibleTo($request->user())->whereIn('ulid', $ulids)->get()->keyBy('ulid');

            if ($students->count() !== $ulids->unique()->count()) {
                return response()->json(['message' => 'Sebagian siswa tidak ditemukan atau bukan wewenang Anda.'], 422);
            }

            $mapped = $entries->map(fn ($r) => [
                'student' => $students[$r['student_ulid']],
                'status' => $r['status'],
                'description' => $r['description'] ?? null,
            ]);

            $ledger->recordBulk($mapped, $session, $request->user());
        }

        $sessions->close($session, $ledger);

        ActivityLog::record($request->user(), 'attendance.session_completed', $session, [
            'manual_records' => $entries->count(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
