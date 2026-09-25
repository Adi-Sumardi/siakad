<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\DailyCheckInRequest;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\DailySession;
use App\Models\Student;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The public half of gate mode (DESAIN-PRESENSI-HARIAN.md §5D): one unguessable
 * slug per unit is the only credential - students have no account in this app,
 * the same unauthenticated-but-token-gated shape as AttendancePresensiController
 * and InvitationController. Never exposes a roster: every lookup is one NIS in,
 * one nickname out, so a leaked slug cannot be used to browse a unit's students.
 *
 * These endpoints only READ today's session - they never lazily create one.
 * Opening a window is a write that belongs to the scheduler and the staff
 * boards; anonymous traffic must not be able to conjure sessions into being.
 */
class DailyGateController extends Controller
{
    public function __construct(private DailyAttendanceService $service) {}

    /** What the landing page needs: whose gate this is, whether the window is open, and what the check-in will demand (QR / location). */
    public function show(string $slug): JsonResponse
    {
        $setting = $this->setting($slug);
        $session = $this->todaySession($setting);

        $state = match (true) {
            ! $setting->enabled => 'inactive',
            $setting->intake_mode !== 'gerbang' => 'wrong_mode',
            $session === null => 'no_session',
            default => ($session->isOpen() ? 'open' : 'closed'),
        };

        return response()->json([
            'unit_label' => $setting->schoolUnit->label,
            'state' => $state,
            'session' => $session ? [
                'type' => $session->type,
                'opens_at' => $session->opens_at?->format('H:i'),
                'closes_at' => $session->closes_at?->format('H:i'),
                'late_after' => $session->late_after?->format('H:i'),
            ] : null,
            'geo' => [
                'required' => $setting->geo_required,
                'gate_lat' => (float) $setting->gate_lat,
                'gate_lng' => (float) $setting->gate_lng,
                'radius_m' => (int) $setting->geo_radius_m,
            ],
            'qr_required' => $setting->qr_required,
        ]);
    }

    /** Resolves a NIS to a nickname for confirmation - writes nothing, demands nothing yet. */
    public function lookup(DailyCheckInRequest $request, string $slug): JsonResponse
    {
        [$setting, $session, $error] = $this->gateOpen($slug);

        if ($error) {
            return $error;
        }

        $student = $this->studentIn($setting, $request->validated('nis'));

        if (! $student) {
            return response()->json(['message' => 'NIS tidak ditemukan di unit ini.'], 404);
        }

        $already = DailyRecord::where('daily_session_id', $session->id)
            ->where('student_id', $student->id)
            ->active()
            ->exists();

        return response()->json([
            'student' => ['nama_panggilan' => $student->nama_panggilan],
            'already_checked_in' => $already,
        ]);
    }

    /** The actual write - every anti-fraud layer is re-validated server-side (see DailyAttendanceService::selfCheckIn). */
    public function checkIn(DailyCheckInRequest $request, string $slug): JsonResponse
    {
        [$setting, $session, $error] = $this->gateOpen($slug);

        if ($error) {
            return $error;
        }

        $student = $this->studentIn($setting, $request->validated('nis'));

        if (! $student) {
            return response()->json(['message' => 'NIS tidak ditemukan di unit ini.'], 404);
        }

        try {
            $record = $this->service->selfCheckIn($session, $student, [
                'device_id' => $request->validated('device_id'),
                'ip' => $request->ip(),
                'lat' => $request->validated('lat') !== null ? (float) $request->validated('lat') : null,
                'lng' => $request->validated('lng') !== null ? (float) $request->validated('lng') : null,
                'accuracy' => $request->validated('accuracy') !== null ? (float) $request->validated('accuracy') : null,
                'qr_code' => $request->validated('qr_code'),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $this->statusFor($e->getMessage()));
        }

        return response()->json([
            'status' => 'ok',
            'student' => ['nama_panggilan' => $student->nama_panggilan],
            'is_late' => $record->is_late,
            'checked_in_at' => $record->checked_in_at?->format('H:i'),
        ]);
    }

    /** @return array{0:DailyAttendanceSetting,1:?DailySession,2:?JsonResponse} setting+session on success, or the error response as slot 2. */
    private function gateOpen(string $slug): array
    {
        $setting = $this->setting($slug);

        if (! $setting->enabled || $setting->intake_mode !== 'gerbang') {
            return [$setting, null, response()->json(['message' => 'Unit ini tidak menerima absen gerbang.'], 404)];
        }

        $session = $this->todaySession($setting);

        if ($session === null) {
            return [$setting, null, response()->json(['message' => 'Belum ada sesi absen hari ini.'], 404)];
        }

        if (! $session->isOpen()) {
            $now = Carbon::now('Asia/Jakarta');
            $label = $session->type === 'pulang' ? 'pulang' : 'masuk';

            if ($now->lt($session->opens_at)) {
                return [$setting, null, response()->json([
                    'message' => 'Jendela absen '.$label.' belum dibuka - mulai pukul '.$session->opens_at?->format('H:i').' WIB.',
                ], 410)];
            }

            return [$setting, null, response()->json(['message' => 'Sesi absen '.$label.' sudah ditutup.'], 410)];
        }

        return [$setting, $session, null];
    }

    private function setting(string $slug): DailyAttendanceSetting
    {
        return DailyAttendanceSetting::where('public_slug', $slug)
            ->with('schoolUnit')
            ->firstOrFail();
    }

    private function todaySession(DailyAttendanceSetting $setting): ?DailySession
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $sessions = DailySession::where('school_unit_id', $setting->school_unit_id)
            ->whereDate('date', $today)
            ->whereIn('type', ['masuk', 'pulang'])
            ->get();

        // The gate serves whichever window is live right now - the same link
        // and screen carry the afternoon pulang too. Deterministic order
        // (masuk first, then id) before the isOpen scan: an overlapping
        // pair from pre-T65 settings data would otherwise pick whichever
        // the DB returned first. When none is open (the midday gap, or
        // before/after everything), fall back to the window nearest in time
        // so the page can honestly say which one and when.
        return $sessions
            ->sortBy(fn (DailySession $s) => [$s->type === 'masuk' ? 0 : 1, $s->id])
            ->first(fn (DailySession $s) => $s->isOpen())
            ?? $sessions->sortBy(fn (DailySession $s) => abs(
                Carbon::now('Asia/Jakarta')->getTimestamp() - $s->opens_at?->getTimestamp()
            ))->first();
    }

    private function studentIn(DailyAttendanceSetting $setting, string $nis): ?Student
    {
        // No user session to scope by, so the unit is the boundary - the slug's
        // own unit. Includes students not yet placed in a classroom: the gate
        // is exactly where they still belong (see the migration's nullable
        // classroom_id note).
        return Student::query()
            ->active()
            ->where('school_unit_id', $setting->school_unit_id)
            ->where('nis', $nis)
            ->first();
    }

    private function statusFor(string $message): int
    {
        return match (true) {
            str_contains($message, 'semester aktif') => 503,
            str_contains($message, 'Sudah tercatat') || str_contains($message, 'Perangkat ini') => 409,
            str_contains($message, 'QR') || str_contains($message, 'lokasi') || str_contains($message, 'area sekolah') || str_contains($message, 'akurat') => 422,
            default => 400,
        };
    }
}
