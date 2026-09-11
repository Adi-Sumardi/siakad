<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\DateRangeRequest;
use App\Models\AttendanceRecord;
use App\Models\Classroom;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClassroomController extends Controller
{
    /**
     * Every classroom in the teacher's unit, not only the one they are
     * homeroom of - Classroom::scopeVisibleTo() already draws that line
     * (teachers teach across a unit, homeroom is just their own room), so this
     * simply reads it rather than narrowing further.
     */
    public function index(Request $request): JsonResponse
    {
        $classrooms = Classroom::query()
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->with('homeroomTeacher')
            ->orderBy('tingkat')->orderBy('name')
            ->get();

        return response()->json([
            'classrooms' => $classrooms->map(fn (Classroom $c) => [
                'ulid' => $c->ulid,
                'name' => $c->name,
                'tingkat' => $c->tingkat,
                'is_homeroom' => $c->homeroom_teacher_id === $request->user()->id,
                'homeroom_teacher' => $c->homeroomTeacher?->name,
                'student_count' => $c->enrollments()->where('status', 'active')->count(),
            ]),
        ]);
    }

    /** The roster, each student's running point balance for the term alongside it. */
    public function students(Request $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::query()
            ->visibleTo($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();

        $term = Term::current();
        $ledger = app(PointLedger::class);

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'students' => $students->map(fn ($student) => [
                'ulid' => $student->ulid,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
                'point_balance' => $term ? $ledger->balance($student, $term) : null,
            ]),
        ]);
    }

    /**
     * H/S/I/A recap for the whole class over a date range - the answer to
     * "who in my class keeps missing school", which the live session roster
     * can never give because it only ever shows one lesson period. Every
     * roster student is listed, zeros included: an all-zero row is itself
     * the finding (never once checked in).
     */
    public function attendanceRecap(DateRangeRequest $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validated();

        $term = Term::current();
        // Default range = active term to date, not month-to-date: absences
        // accumulate consequences over a whole semester, and the enrollments
        // absent/sick/permit summaries are term-scoped too.
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay()
            : ($term ? Carbon::parse($term->starts_on)->startOfDay() : now('Asia/Jakarta')->startOfMonth());
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now('Asia/Jakarta')->endOfDay();

        $students = $classroom->enrollments()
            ->where('status', 'active')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('nama_lengkap')
            ->values();

        $tallies = AttendanceRecord::query()
            ->active()
            // classroom_id + occurred_on are denormalized onto every record
            // exactly so a report like this never joins through the schedule;
            // occurred_on carries a time component, so bound with
            // start/end-of-day timestamps (same note as the admin report).
            ->where('classroom_id', $classroom->id)
            ->whereBetween('occurred_on', [$from, $to])
            ->selectRaw('student_id, attendance_status, count(*) as n')
            ->groupBy('student_id', 'attendance_status')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('n', 'attendance_status'));

        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'students' => $students->map(fn ($student) => [
                'ulid' => $student->ulid,
                'nama_lengkap' => $student->nama_lengkap,
                'nis' => $student->nis,
                'hadir' => (int) ($tallies->get($student->id)?->get('hadir') ?? 0),
                'sakit' => (int) ($tallies->get($student->id)?->get('sakit') ?? 0),
                'izin' => (int) ($tallies->get($student->id)?->get('izin') ?? 0),
                'alpa' => (int) ($tallies->get($student->id)?->get('alpa') ?? 0),
            ]),
        ]);
    }

    /** Today's lesson periods for one classroom, so a teacher can pick which one to open attendance for. */
    public function schedulesToday(Request $request, string $ulid): JsonResponse
    {
        $classroom = Classroom::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        // config('app.timezone') is UTC, not the Asia/Jakarta .env sets it to
        // (config/app.php never reads the env var) - a bare Carbon::now()
        // reports the wrong day of week for the seven hours every morning
        // (00:00-07:00 WIB) that fall on the previous UTC day, which is
        // exactly when a teacher opens this screen to take attendance.
        $today = Carbon::now('Asia/Jakarta')->dayOfWeekIso; // 1 = Senin ... 7 = Minggu

        $schedules = $classroom->classSchedules()
            ->where('day_of_week', $today)
            ->with('subject', 'teacher')
            ->orderBy('start_time')
            ->get();

        // Every period of the class's day is shown (a homeroom teacher wants
        // the whole picture), but only the assigned teacher may open roll
        // call (AttendanceSessionController::open() enforces teacher_id) -
        // so flag ownership and let the frontend hide the button for periods
        // that would only 404.
        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'schedules' => $schedules->map(fn ($s) => [
                'ulid' => $s->ulid,
                'subject' => $s->subject->name,
                'teacher' => $s->teacher?->name,
                'is_mine' => $s->teacher_id === $request->user()->id,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
            ]),
        ]);
    }
}
