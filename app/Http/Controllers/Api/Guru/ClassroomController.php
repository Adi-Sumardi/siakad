<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guru\DateRangeRequest;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\DailyRecord;
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

        // Explicit Asia/Jakarta even though app.timezone now reads the env
        // (default Jakarta): the browser never matters here, and this stays
        // correct if the env ever flips back to UTC. Same reasoning as
        // schedulesToday() below.
        $today = Carbon::now('Asia/Jakarta')->dayOfWeekIso; // 1 = Senin ... 7 = Minggu

        // One query for every listed classroom's periods today, so the
        // dashboard can float "classes with lessons today" to its own section
        // without N+1-ing the schedule per card.
        $todaySchedules = ClassSchedule::query()
            ->whereIn('classroom_id', $classrooms->pluck('id'))
            ->where('day_of_week', $today)
            ->get()
            ->groupBy('classroom_id');

        return response()->json([
            'classrooms' => $classrooms->map(fn (Classroom $c) => [
                'ulid' => $c->ulid,
                'name' => $c->name,
                'tingkat' => $c->tingkat,
                'is_homeroom' => $c->homeroom_teacher_id === $request->user()->id,
                'homeroom_teacher' => $c->homeroomTeacher?->name,
                'student_count' => $c->enrollments()->where('status', 'active')->count(),
                'schedules_today' => $this->summarizeToday($todaySchedules->get($c->id), $request->user()->id),
            ]),
        ]);
    }

    /**
     * The dashboard's "has lessons today" signal: how many periods the class
     * runs today, which of those the requesting teacher teaches themselves
     * (split by realtime status, so "Anda Mengajar" can stop glowing on a
     * period that ended hours ago), and the day's first/last bell. start/end
     * times are plain "HH:MM:SS" strings, so min()/max() compare correctly
     * as strings.
     */
    private function summarizeToday($schedules, int $userId): array
    {
        $schedules ??= collect();
        $mine = $schedules->where('teacher_id', $userId);

        return [
            'total' => $schedules->count(),
            'mine' => $mine->count(),
            'mine_ongoing' => $mine->filter(fn ($s) => $this->periodIs($s, 'ongoing'))->count(),
            'mine_upcoming' => $mine->filter(fn ($s) => $this->periodIs($s, 'upcoming'))->count(),
            'first_start' => $schedules->min('start_time'),
            'last_end' => $schedules->max('end_time'),
        ];
    }

    /** The period's realtime state - upcoming/ongoing/done - judged on the Jakarta wall clock ("HH:MM:SS" strings compare correctly). */
    private function periodStatus(ClassSchedule $schedule): string
    {
        $now = Carbon::now('Asia/Jakarta')->format('H:i:s');
        $start = substr((string) $schedule->start_time, 0, 8);
        $end = substr((string) $schedule->end_time, 0, 8);

        return $now < $start ? 'upcoming' : ($now > $end ? 'done' : 'ongoing');
    }

    private function periodIs(ClassSchedule $schedule, string $want): bool
    {
        return $this->periodStatus($schedule) === $want;
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

        $tallies = DailyRecord::query()
            ->active()
            // The daily layer is the official source (§8): these are DAYS a
            // student was present/sick/absent, not lesson periods - the same
            // numbers the rapor and the watchlist quote. classroom_id + date
            // are denormalized onto every row so this never joins through
            // the session. date can carry a midnight time component
            // depending on the driver, so bound with full start/end-of-day
            // timestamps (same note as the admin report). Pulang windows are
            // excluded: they retell the same day's story, they don't add a
            // second day of it.
            ->where('classroom_id', $classroom->id)
            ->whereBetween('date', [$from, $to])
            ->whereHas('dailySession', fn ($q) => $q->where('type', 'masuk'))
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

        // Explicit Asia/Jakarta rather than relying on app.timezone (now
        // env-read, default Jakarta): this stays correct even if the env
        // ever flips back to UTC, which used to misdate the day of week for
        // 00:00-07:00 WIB - exactly when a teacher opens this screen.
        $today = Carbon::now('Asia/Jakarta')->dayOfWeekIso; // 1 = Senin ... 7 = Minggu

        $schedules = $classroom->classSchedules()
            ->where('day_of_week', $today)
            ->with('subject', 'teacher')
            ->orderBy('start_time')
            ->get();

        // Every period of the class's day is shown (a homeroom teacher wants
        // the whole picture), but only the assigned teacher may open roll
        // call (AttendanceSessionController::open() enforces teacher_id) -
        // so flag ownership and realtime state, and let the frontend hide
        // the button for periods that would only 404 or are already over.
        return response()->json([
            'classroom' => ['ulid' => $classroom->ulid, 'name' => $classroom->name],
            'schedules' => $schedules->map(fn ($s) => [
                'ulid' => $s->ulid,
                'subject' => $s->subject->name,
                'teacher' => $s->teacher?->name,
                'is_mine' => $s->teacher_id === $request->user()->id,
                'status' => $this->periodStatus($s),
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
            ]),
        ]);
    }
}
