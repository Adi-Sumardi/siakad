<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarkDailyRecordRequest;
use App\Models\DailySession;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\RotatingQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The TU/admin board for gate mode (DESAIN-PRESENSI-HARIAN.md §5D): which
 * windows exist, live H/S/I/A tallies, the rotating QR to hold up at the
 * gate, the manual NIS fallback for a student whose scan keeps failing, and
 * the layer-5 flags for students whose check-ins share an IP. Writes happen
 * only through the one sanctioned path - DailyAttendanceService - and the
 * fallback for an unmarked student is always the close-time alpa sweep,
 * never a manual "just in case" write here.
 */
class DailyAttendanceSessionController extends Controller
{
    public function today(Request $request, DailyAttendanceService $service): JsonResponse
    {
        $unit = $this->resolveUnit($request);
        $setting = $service->ensureSettings($unit);

        // Lazy ensure: the scheduler is the normal opener, but the board must
        // not show a blank morning just because it is down - opening here is
        // the same idempotent firstOrCreate either way.
        $sessions = $service->ensureSessionsForDate($setting);

        return response()->json([
            'date' => Carbon::now('Asia/Jakarta')->toDateString(),
            'enabled' => $setting->enabled,
            'intake_mode' => $setting->intake_mode,
            'sessions' => $sessions->values()->map(fn (DailySession $session) => [
                'ulid' => $session->ulid,
                'type' => $session->type,
                'status' => $session->status,
                'opens_at' => $session->opens_at?->format('H:i'),
                'closes_at' => $session->closes_at?->format('H:i'),
                'late_after' => $session->late_after?->format('H:i'),
                'tally' => $service->tally($session),
                'roster' => $service->roster($session),
                // Only meaningful in gate mode; computed cheap and skipped
                // otherwise so a wali_kelas unit's board never carries it.
                'suspected' => $setting->intake_mode === 'gerbang' && $session->type === 'masuk'
                    ? $service->suspectedShares($session)
                    : [],
                // The TU's eyes: newest self check-ins going by (gate mode)
                // and the gate-vs-lesson cross-check, both masuk-only.
                'recent' => $setting->intake_mode === 'gerbang' && $session->type === 'masuk'
                    ? $service->recentCheckIns($session)
                    : [],
                'discrepancy' => $session->type === 'masuk'
                    ? $service->lessonDiscrepancy($session)
                    : ['available' => false, 'no_lesson' => [], 'no_gate' => []],
            ]),
        ]);
    }

    /** The code the TU's "layar QR" renders right now, plus how long until it goes stale - the screen polls back after exactly that long. */
    public function gateQr(Request $request, string $ulid, RotatingQrService $qr): JsonResponse
    {
        $session = $this->ownSession($request, $ulid);

        if (! $session->isOpen()) {
            return response()->json(['message' => 'Sesi ini sudah ditutup.'], 410);
        }

        return response()->json([
            'code' => $qr->code(RotatingQrService::dailyScope($session->ulid)),
            'rotates_in' => $qr->secondsUntilRotation(),
            'window_seconds' => RotatingQrService::WINDOW_SECONDS,
        ]);
    }

    /**
     * TU's manual mark - the quick lane for a student whose scan failed and
     * the correction lane after a parent calls in ('ALPA ternyata SAKIT').
     * Re-marking supersedes, so this one endpoint covers both.
     */
    public function mark(MarkDailyRecordRequest $request, string $ulid, DailyAttendanceService $service): JsonResponse
    {
        $session = $this->ownSession($request, $ulid);

        $student = Student::where('ulid', $request->validated('student_ulid'))->firstOrFail();

        if ($student->school_unit_id !== $session->school_unit_id) {
            // R3, as always: another unit's student is "not found", never "forbidden".
            abort(404);
        }

        try {
            $record = $service->mark(
                $session,
                $student,
                $request->validated('status'),
                $request->user(),
                'tu',
                $request->validated('description'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'record_ulid' => $record->ulid,
            'attendance_status' => $record->attendance_status,
            'is_late' => $record->is_late,
        ]);
    }

    /** Same scope line as every staff-facing session endpoint: a unit admin's own unit, 404 for anyone else's. */
    private function ownSession(Request $request, string $ulid): DailySession
    {
        $session = DailySession::where('ulid', $ulid)->firstOrFail();

        if ($request->user()->isUnitScoped() && $session->school_unit_id !== $request->user()->school_unit_id) {
            abort(404);
        }

        return $session;
    }

    private function resolveUnit(Request $request): SchoolUnit
    {
        if ($request->user()->isUnitScoped()) {
            return $request->user()->schoolUnit ?: abort(404);
        }

        return SchoolUnit::where('ulid', $request->input('unit'))->firstOrFail();
    }
}
