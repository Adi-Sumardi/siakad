<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\MarkDailyAttendanceRequest;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DailySession;
use App\Models\Student;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The homeroom teacher's half of the daily attendance layer - the marking
 * board for mode 'wali_kelas' (PG/RA/TK/SD, see DESAIN-PRESENSI-HARIAN.md
 * §5C). Scope is drawn twice, both times toward 404: the session must belong
 * to the teacher's own unit (R3), and the student must sit in a classroom the
 * teacher is homeroom of - a teacher of unit A never even learns that unit
 * B's session exists, and within their own unit they mark their own roster,
 * not the neighbours'.
 */
class DailyAttendanceController extends Controller
{
    public function __construct(private DailyAttendanceService $service) {}

    public function today(Request $request): JsonResponse
    {
        $unit = $request->user()->schoolUnit ?: abort(404);
        $setting = $this->service->ensureSettings($unit);

        try {
            $date = $this->service->resolveBoardDate($request->query('date'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Today lazily opens its windows; a past date is correction mode and
        // only reads what history holds (audit 6 Okt 2026 #1).
        $sessions = $this->service->sessionsForDate($setting, $date);

        $homeroomIds = $this->homeroomClassroomIds($request);

        return response()->json([
            'date' => $date->toDateString(),
            'is_today' => $date->isSameDay(Carbon::now('Asia/Jakarta')),
            'correction_window_days' => DailyAttendanceService::CORRECTION_WINDOW_DAYS,
            'enabled' => $setting->enabled,
            'intake_mode' => $setting->intake_mode,
            'homeroom_classrooms' => Classroom::whereIn('id', $homeroomIds)->orderBy('name')->get()
                ->map(fn ($c) => ['ulid' => $c->ulid, 'name' => $c->name]),
            'sessions' => $sessions->values()->map(fn ($session) => [
                'ulid' => $session->ulid,
                'type' => $session->type,
                'status' => $session->status,
                'is_open' => $session->isOpen(),
                'opens_at' => $session->opens_at?->format('H:i'),
                'closes_at' => $session->closes_at?->format('H:i'),
                'late_after' => $session->late_after?->format('H:i'),
                'roster' => $this->service->roster($session, $homeroomIds),
            ]),
        ]);
    }

    public function mark(MarkDailyAttendanceRequest $request, string $ulid): JsonResponse
    {
        $session = DailySession::where('ulid', $ulid)->firstOrFail();

        if ($session->school_unit_id !== $request->user()->school_unit_id) {
            abort(404);
        }

        $student = Student::where('ulid', $request->validated('student_ulid'))->firstOrFail();

        if (! $this->homeroomClassroomIds($request)->contains(
            $student->currentEnrollment()?->classroom_id
        )) {
            // Same direction as visibleTo: a student outside the teacher's
            // own rooms is "not found", never "forbidden".
            abort(404);
        }

        try {
            $this->service->assertMarkable($session, $request->validated('description'));

            $record = $this->service->mark(
                $session,
                $student,
                $request->validated('status'),
                $request->user(),
                'wali_kelas',
                $request->validated('description'),
            );
        } catch (RuntimeException $e) {
            // 422, not 503 (audit 2026-10-05) - same reason as the admin
            // session lane: these are caller-fixable messages, not outages.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'record_ulid' => $record->ulid,
            'attendance_status' => $record->attendance_status,
            'is_late' => $record->is_late,
        ]);
    }

    /**
     * "Tandai semua hadir" in one request - the board used to fire one POST
     * per student in sequence, seconds of spinner on a phone at 07:00. Only
     * still-blank rows are touched: a scan, an earlier mark or a correction
     * is never overwritten. A child whose guardian notice was approved is
     * marked with that notice on the masuk window (the board already shows
     * it; "hadir" would contradict it) and left alone on pulang.
     */
    public function markAll(Request $request, string $ulid): JsonResponse
    {
        $session = DailySession::where('ulid', $ulid)->firstOrFail();

        if ($session->school_unit_id !== $request->user()->school_unit_id) {
            abort(404);
        }

        if (! $session->date->isSameDay(Carbon::now('Asia/Jakarta'))) {
            return response()->json(['message' => 'Tandai semua hanya untuk presensi hari ini - hari yang lewat dikoreksi satu per satu beserta alasannya.'], 422);
        }

        $blank = $this->service->roster($session, $this->homeroomClassroomIds($request))
            ->filter(fn (array $row) => $row['attendance_status'] === null)
            ->reject(fn (array $row) => $session->type === 'pulang' && $row['leave'] !== null);

        $students = Student::whereIn('ulid', $blank->pluck('ulid'))->get()->keyBy('ulid');
        $tally = ['hadir' => 0, 'sakit' => 0, 'izin' => 0];

        try {
            foreach ($blank as $row) {
                $status = $session->type === 'masuk' && $row['leave'] ? $row['leave'] : 'hadir';

                $this->service->mark(
                    $session,
                    $students[$row['ulid']],
                    $status,
                    $request->user(),
                    'wali_kelas',
                    $status === 'hadir' ? null : 'Pengajuan wali disetujui.',
                );

                $tally[$status]++;
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['marked' => array_sum($tally), 'tally' => $tally]);
    }

    /**
     * The teacher's own rooms in the current academic year - empty for a
     * teacher who is nobody's homeroom, which fails closed in roster().
     */
    private function homeroomClassroomIds(Request $request)
    {
        $yearId = AcademicYear::current()?->id;

        return Classroom::query()
            ->where('homeroom_teacher_id', $request->user()->id)
            ->where('school_unit_id', $request->user()->school_unit_id)
            ->where('is_active', true)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->pluck('id');
    }
}
