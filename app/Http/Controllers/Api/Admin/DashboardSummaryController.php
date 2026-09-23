<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardSummaryRequest;
use App\Models\AcademicYear;
use App\Models\SchoolUnit;
use App\Models\Term;
use App\Services\Academic\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One payload for the whole executive dashboard (Ringkasan), both admin kinds.
 *
 * Scope is enforced the same way the rest of the admin area enforces it: the
 * unit list is narrowed to the caller's own unit when they are unit-scoped,
 * and every aggregation is built from that (already narrowed) unit list plus
 * identically-scoped rows, so one response shape cannot accidentally leak
 * another unit's numbers to a per-unit admin. The frontend decides how much
 * to render from role + the `scope.is_central` flag.
 *
 * The money/count aggregates are GROUPED SQL, never hydrated models - at two
 * thousand students this payload used to materialise every bill, grade and
 * achievement as an Eloquent object just to count them. What still walks row
 * by row (students, enrollments, grades, points - the per-student inputs
 * WatchlistService needs) comes back as bare stdClass rows: the watchlist's
 * rounding semantics stay in PHP, byte-identical on every driver.
 */
class DashboardSummaryController extends Controller
{
    public function summary(DashboardSummaryRequest $request, WatchlistService $watchlist): JsonResponse
    {
        $user = $request->user();
        $year = AcademicYear::current() ?? AcademicYear::latest('starts_on')->first();

        // Which period the money numbers cover (T23): the running academic
        // year by default, or every bill ever with ?billing_period=all.
        $billingPeriod = $request->input('billing_period') === 'all' ? 'all' : 'year';
        $billingLabel = $billingPeriod === 'year' && $year?->year
            ? 'TA '.$year->year
            : 'Semua periode';
        // One definition of "the running semester" for the whole app: the
        // active term, exactly what the grade/point write lanes file under.
        $term = Term::current();
        $prevTerm = $watchlist->previousTerm($term);

        $units = SchoolUnit::active()->ordered()
            ->when($user?->isUnitScoped(), fn ($q) => $q->where('id', $user->school_unit_id))
            ->get(['id', 'code', 'label', 'jenjang_group', 'sort_order']);

        // The row scoping Student::visibleTo() draws, spelled out for the raw
        // queries below: central sees everyone (soft-deleted included in
        // neither lane), a unit-scoped admin only their own unit.
        $isUnitScoped = (bool) $user?->isUnitScoped();
        $ownUnitId = $user?->school_unit_id;
        $studentScope = fn ($q) => $isUnitScoped
            ? ($ownUnitId ? $q->where('students.school_unit_id', $ownUnitId) : $q->whereRaw('1 = 0'))
            : $q;

        // "Lewat jatuh tempo" in SQL: a due date at midnight is past the
        // moment its day starts, so date <= today == due_date < tomorrow.
        // This mirrors Bill::isOverdue() (isPast(), i.e. vs now), which is a
        // hair different from the status writer's "before start of today" -
        // each keeps its own semantics on purpose.
        $overdueEdge = Carbon::tomorrow('Asia/Jakarta')->toDateString();

        // ---- Students (light rows: the watchlist needs them per student) ---
        $students = new Collection(DB::table('students')
            ->whereNull('students.deleted_at')
            ->when($isUnitScoped, $studentScope)
            ->get(['students.id', 'students.school_unit_id', 'students.status', 'students.entry_year_id']));
        $studentById = $students->keyBy('id');

        // Current-year active placements + rollups (alpa etc. live on the enrollment).
        $enrollments = $year
            ? new Collection(DB::table('enrollments')
                ->where('academic_year_id', $year->id)
                ->where('status', 'active')
                ->whereIn('student_id', $studentById->keys()->all())
                ->get(['student_id', 'absent_count']))
            : collect();

        $classroomCounts = $year
            ? DB::table('classrooms')
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->when($isUnitScoped, fn ($q) => $q->where('school_unit_id', $ownUnitId))
                ->selectRaw('school_unit_id, count(*) as total')
                ->groupBy('school_unit_id')
                ->pluck('total', 'school_unit_id')
            : collect();

        $teacherCounts = DB::table('users')
            ->where('role', 'guru')
            ->where('is_active', true)
            ->whereNotNull('school_unit_id')
            ->when($isUnitScoped, fn ($q) => $q->where('school_unit_id', $ownUnitId))
            ->selectRaw('school_unit_id, count(*) as total')
            ->groupBy('school_unit_id')
            ->pluck('total', 'school_unit_id');

        // ---- Billing (one grouped query, money never walks row by row) -----
        // Scoped like the rest of the payload (T23): the year view only sums
        // bills issued for the running academic year. Grouped by the
        // STUDENT'S unit, which is what Bill::visibleTo() resolves to; a
        // central admin's buckets can therefore include inactive units -
        // those feed the alerts but not the per-unit/gand totals, exactly as
        // the hydrated version behaved.
        $billBuckets = new Collection(DB::table('bills')
            ->join('students', 'students.id', '=', 'bills.student_id')
            ->whereNull('students.deleted_at')
            ->whereNotIn('bills.status', ['cancelled'])
            ->when($billingPeriod === 'year' && $year, fn ($q) => $q->where('bills.academic_year_id', $year->id))
            ->when($isUnitScoped, fn ($q) => $q->where('students.school_unit_id', $ownUnitId))
            ->selectRaw(
                'students.school_unit_id as unit_id, count(*) as bill_count,'
                .' coalesce(sum(bills.total_amount), 0) as billed,'
                .' coalesce(sum(bills.paid_amount), 0) as paid,'
                .' coalesce(sum(bills.remaining_amount), 0) as outstanding,'
                ." sum(case when bills.status in ('unpaid','partial','overdue') and bills.due_date < ? then 1 else 0 end) as overdue_bills,"
                ." sum(case when bills.status in ('unpaid','partial','overdue') and bills.due_date < ? then bills.remaining_amount else 0 end) as overdue_amount,"
                ." sum(case when bills.status = 'unpaid' then 1 else 0 end) as unpaid_count,"
                ." sum(case when bills.status = 'partial' then 1 else 0 end) as partial_count,"
                ." count(distinct case when bills.status in ('unpaid','partial','overdue') then bills.student_id end) as debtor_students",
                [$overdueEdge, $overdueEdge],
            )
            ->groupBy('students.school_unit_id')
            ->get());
        $billByUnit = $billBuckets->keyBy('unit_id');

        // ---- Attendance (term-to-date, aggregated in SQL) -------------------
        // The daily layer is the official source (T14 §8): these counts are
        // DAYS present/sick/absent, not lesson periods, so a PG/TK unit with
        // no subject schedule counts the same as an SMA one. Masuk windows
        // only - a pulang row retells the same day.
        $scopeUnitIds = $units->pluck('id')->all();

        $attendanceRows = $term
            ? (new Collection(DB::table('daily_records')
                ->join('daily_sessions', 'daily_sessions.id', '=', 'daily_records.daily_session_id')
                ->where('daily_records.term_id', $term->id)
                ->where('daily_records.record_status', 'recorded')
                ->where('daily_sessions.type', 'masuk')
                ->whereIn('daily_sessions.school_unit_id', $scopeUnitIds)
                ->selectRaw('daily_sessions.school_unit_id, daily_records.attendance_status, count(*) as total')
                ->groupBy('daily_sessions.school_unit_id', 'daily_records.attendance_status')
                ->get()))
            : collect();

        // "Kehadiran hari ini" - same tally restricted to today's Jakarta
        // calendar date (the daily layer's date IS the Jakarta date, never
        // the UTC one app.timezone would pick). whereDate, not a bare
        // equality string: the column may store a midnight time component
        // depending on the driver.
        $attendanceToday = (new Collection(DB::table('daily_records')
            ->join('daily_sessions', 'daily_sessions.id', '=', 'daily_records.daily_session_id')
            ->whereDate('daily_records.date', Carbon::now('Asia/Jakarta')->toDateString())
            ->where('daily_records.record_status', 'recorded')
            ->where('daily_sessions.type', 'masuk')
            ->whereIn('daily_sessions.school_unit_id', $scopeUnitIds)
            ->selectRaw('daily_sessions.school_unit_id, daily_records.attendance_status, count(*) as total')
            ->groupBy('daily_sessions.school_unit_id', 'daily_records.attendance_status')
            ->get()));

        // ---- Grades (current + previous term, for KKM & decline alerts) -----
        // stdClass rows into the UNTOUCHED WatchlistService: its rounding
        // (per-subject round-2 over complete subjects only, then per-student
        // round-2) stays in PHP so SQLite tests and Postgres production read
        // identically. Also feeds /admin/perhatian through the same service.
        $gradeTermIds = array_values(array_filter([$term?->id, $prevTerm?->id]));
        $grades = ! empty($gradeTermIds)
            ? new Collection(DB::table('grades')
                ->join('students', 'students.id', '=', 'grades.student_id')
                ->whereNull('students.deleted_at')
                ->when($isUnitScoped, fn ($q) => $q->where('students.school_unit_id', $ownUnitId))
                ->whereIn('grades.term_id', $gradeTermIds)
                ->get([
                    'grades.student_id', 'grades.subject_id', 'grades.term_id', 'grades.category',
                    'grades.score', 'students.school_unit_id',
                ]))
            : collect();

        // ---- Achievements (grouped, unit attributed like visibleTo) --------
        // Attribution: explicit unit, else the student's, else the teacher's
        // - the same COALESCE over joins, with soft-deleted students joined
        // out because that is what the Eloquent relation did. A unit-scoped
        // admin's three-way OR mirrors Achievement::visibleTo().
        $achievementBuckets = new Collection(DB::table('achievements as a')
            ->leftJoin('students as s', fn ($j) => $j->on('s.id', '=', 'a.student_id')->whereNull('s.deleted_at'))
            ->leftJoin('users as t', 't.id', '=', 'a.teacher_user_id')
            ->when($isUnitScoped, fn ($q) => $q->where(function ($w) use ($ownUnitId) {
                $w->where('a.school_unit_id', $ownUnitId)
                    ->orWhere('s.school_unit_id', $ownUnitId)
                    ->orWhere('t.school_unit_id', $ownUnitId);
            }))
            ->selectRaw('COALESCE(a.school_unit_id, s.school_unit_id, t.school_unit_id) as unit_id, a.status, a.achiever_type, count(*) as total')
            ->groupBy('unit_id', 'a.status', 'a.achiever_type')
            ->get());

        $achCount = function (Collection $buckets, ?int $unitId, string $status, ?string $type = null) use ($units): int {
            return (int) $buckets
                ->filter(fn ($r) => ($unitId === null ? $units->contains('id', (int) $r->unit_id) : (int) $r->unit_id === $unitId)
                    && $r->status === $status
                    && ($type === null || $r->achiever_type === $type || ($type === 'siswa' && blank($r->achiever_type))))
                ->sum('total');
        };

        // ---- Points (term-to-date; light rows, the watchlist reads each) ---
        $points = $term
            ? (new Collection(DB::table('point_records')
                ->join('students', 'students.id', '=', 'point_records.student_id')
                ->where('point_records.term_id', $term->id)
                ->where('point_records.status', 'recorded')
                ->whereIn('students.school_unit_id', $scopeUnitIds)
                ->selectRaw('students.school_unit_id, point_records.student_id, point_records.type, point_records.points')
                ->get()))
            : collect();

        // ---- Extracurriculars (one grouped left join) -----------------------
        $ekskulBuckets = $year
            ? new Collection(DB::table('extracurriculars as e')
                ->leftJoin('extracurricular_members as m', fn ($j) => $j->on('m.extracurricular_id', '=', 'e.id')->where('m.status', 'active'))
                ->where('e.academic_year_id', $year->id)
                ->where('e.is_active', true)
                ->when($isUnitScoped, fn ($q) => $q->where('e.school_unit_id', $ownUnitId))
                ->selectRaw('e.school_unit_id as unit_id, count(distinct e.id) as activities, count(m.id) as members')
                ->groupBy('e.school_unit_id')
                ->get()->keyBy('unit_id'))
            : collect();

        // ---- Watchlist (shared with the /admin/perhatian drill-down) ---------
        $watch = $watchlist->identify($students, $enrollments, $grades, $points, $term, $prevTerm);
        $watchByUnit = $watch->groupBy(fn (array $row) => $studentById->get($row['student_id'])?->school_unit_id);
        $currentAverages = $watchlist->perStudentAverages($grades, $term?->id);

        // ---- Per-unit + grand aggregates -------------------------------------
        $unitsData = [];
        $grand = $this->emptyGrandTotals();

        foreach ($units as $unit) {
            $unitStudents = $students->where('school_unit_id', $unit->id);
            $unitEnrollments = $enrollments->filter(
                fn ($e) => $studentById->get($e->student_id)?->school_unit_id === $unit->id,
            );
            $b = $billByUnit->get($unit->id);
            $att = $this->attendanceTallies($attendanceRows->where('school_unit_id', $unit->id));
            $attTotal = array_sum($att);
            $attRate = $attTotal > 0 ? round(($att['hadir'] / $attTotal) * 100, 1) : null;

            $attToday = $this->attendanceTallies($attendanceToday->where('school_unit_id', $unit->id));
            $attTodayTotal = array_sum($attToday);
            $attTodayRate = $attTodayTotal > 0 ? round(($attToday['hadir'] / $attTodayTotal) * 100, 1) : null;

            // Watchlist counts per unit - the unit-scoped academic attention
            // strip, grouped from the same WatchlistService rows the
            // /admin/perhatian drill-down lists names from.
            $unitWatch = $watchByUnit->get($unit->id, collect());
            $withReason = fn (string $reason) => $unitWatch->filter(fn (array $row) => in_array($reason, $row['reasons'], true));

            $unitCurScores = $currentAverages->only($unitStudents->pluck('id')->all());
            $belowKkm = $unitCurScores->filter(fn (float $s) => $s < WatchlistService::KKM)->count();

            $violationStudents = $withReason('point_violation');
            $highAlpaIds = $withReason('absenteeism');
            $declinedIds = $withReason('grade_decline');
            $attentionIds = $unitWatch->keys();

            $unitPoints = $points->where('school_unit_id', $unit->id);
            $ekskul = $ekskulBuckets->get($unit->id);

            $billed = (float) ($b->billed ?? 0);
            $paid = (float) ($b->paid ?? 0);
            $outstanding = (float) ($b->outstanding ?? 0);

            $unitsData[] = [
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'unit_label' => $unit->label,
                'jenjang' => strtoupper($unit->jenjang_group),
                'students_active' => $unitStudents->where('status', 'active')->count(),
                'students_new' => $unitStudents->where('status', 'active')->where('entry_year_id', $year?->id)->count(),
                'enrolled_students' => $unitEnrollments->pluck('student_id')->unique()->count(),
                'classrooms' => (int) ($classroomCounts[$unit->id] ?? 0),
                'teachers' => (int) ($teacherCounts[$unit->id] ?? 0),
                'billed' => $billed,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'collection_rate' => $billed > 0 ? round(($paid / $billed) * 100, 1) : 0,
                'overdue_bills' => (int) ($b->overdue_bills ?? 0),
                'attendance_rate' => $attRate,
                'attendance_today' => $attToday,
                'attendance_today_rate' => $attTodayRate,
                'achievements' => $achCount($achievementBuckets, $unit->id, 'verified'),
                'achievements_pending' => $achCount($achievementBuckets, $unit->id, 'pending'),
                'grades_below_kkm' => $belowKkm,
                'grades_graded' => $unitCurScores->count(),
                'grades_average' => $unitCurScores->isEmpty() ? null : round($unitCurScores->avg(), 1),
                'students_high_absenteeism' => $highAlpaIds->count(),
                'students_declined' => $declinedIds->count(),
                'students_needing_attention' => $attentionIds->count(),
                'extracurriculars' => (int) ($ekskul->activities ?? 0),
                'extracurricular_members' => (int) ($ekskul->members ?? 0),
                'violation_students' => $violationStudents->count(),
                'points_merit_records' => $unitPoints->filter(fn ($p) => (int) $p->points > 0)->count(),
                'points_violation_records' => $unitPoints->filter(fn ($p) => (int) $p->points < 0)->count(),
            ];

            // Grand totals
            $grand['students_active'] += $unitStudents->where('status', 'active')->count();
            $grand['students_new'] += $unitStudents->where('status', 'active')->where('entry_year_id', $year?->id)->count();
            $grand['enrolled_students'] += $unitEnrollments->pluck('student_id')->unique()->count();
            $grand['classrooms'] += (int) ($classroomCounts[$unit->id] ?? 0);
            $grand['teachers'] += (int) ($teacherCounts[$unit->id] ?? 0);
            $grand['billing']['total_billed'] += $billed;
            $grand['billing']['total_paid'] += $paid;
            $grand['billing']['total_outstanding'] += $outstanding;
            $grand['billing']['bill_count'] += (int) ($b->bill_count ?? 0);
            $grand['billing']['overdue_bills'] += (int) ($b->overdue_bills ?? 0);
            $grand['billing']['overdue_amount'] += (float) ($b->overdue_amount ?? 0);
            $grand['billing']['unpaid_count'] += (int) ($b->unpaid_count ?? 0);
            $grand['billing']['partial_count'] += (int) ($b->partial_count ?? 0);
            foreach (['hadir', 'sakit', 'izin', 'alpa'] as $status) {
                $grand['attendance'][$status] += $att[$status];
                $grand['attendance_today'][$status] += $attToday[$status];
            }
            $grand['achievements']['verified'] += $achCount($achievementBuckets, $unit->id, 'verified');
            $grand['achievements']['pending'] += $achCount($achievementBuckets, $unit->id, 'pending');
            $grand['achievements']['siswa'] += $achCount($achievementBuckets, $unit->id, 'verified', 'siswa');
            $grand['achievements']['guru'] += $achCount($achievementBuckets, $unit->id, 'verified', 'guru');
            $grand['grades']['below_kkm'] += $belowKkm;
            $grand['points']['violation_students'] += $violationStudents->count();
            $grand['points']['violation_records'] += $unitPoints->filter(fn ($p) => (int) $p->points < 0)->count();
            $grand['points']['merit_records'] += $unitPoints->filter(fn ($p) => (int) $p->points > 0)->count();
            $grand['extracurriculars']['activities'] += (int) ($ekskul->activities ?? 0);
            $grand['extracurriculars']['members'] += (int) ($ekskul->members ?? 0);
        }

        // ---- Grade-wide average (of per-student final averages, current term)
        $grand['grades']['students_graded'] = 0;
        $grand['grades']['average'] = 0;
        if ($term) {
            $grand['grades']['students_graded'] = $currentAverages->count();
            $grand['grades']['average'] = $currentAverages->isEmpty()
                ? 0
                : round($currentAverages->avg(), 1);
            $grand['grades']['below_kkm'] = $currentAverages
                ->filter(fn (float $s) => $s < WatchlistService::KKM)->count();
            $grand['grades']['declined'] = $watch
                ->filter(fn (array $row) => in_array('grade_decline', $row['reasons'], true))->count();
        }

        $grand['billing']['collection_rate'] = $grand['billing']['total_billed'] > 0
            ? round(($grand['billing']['total_paid'] / $grand['billing']['total_billed']) * 100, 1)
            : 0;
        $grandAttTotal = array_sum($grand['attendance']);
        $grand['attendance']['rate'] = $grandAttTotal > 0
            ? round(($grand['attendance']['hadir'] / $grandAttTotal) * 100, 1)
            : null;

        $grandAttTodayTotal = array_sum($grand['attendance_today']);
        $grand['attendance_today']['total'] = $grandAttTodayTotal;
        $grand['attendance_today']['rate'] = $grandAttTodayTotal > 0
            ? round(($grand['attendance_today']['hadir'] / $grandAttTodayTotal) * 100, 1)
            : null;

        // ---- Alerts / watchlist ----------------------------------------------
        // Money alerts link to the tagihan list pre-scoped to the same period
        // the dashboard counts came from, so the numbers line up on arrival.
        $tagihanHref = $billingPeriod === 'year' && $year?->year
            ? '/admin/tagihan?year='.rawurlencode($year->year)
            : '/admin/tagihan';
        $alerts = $this->buildAlerts(
            $billBuckets, $enrollments, $studentById, $units, $term, $achievementBuckets, $watch,
            $billingLabel, $tagihanHref,
        );

        return response()->json([
            'period' => [
                'academic_year' => $year?->year,
                'term' => $term?->name,
                'term_label' => $term ? ucfirst($term->name).' '.$year?->year : null,
                'term_ulid' => $term?->ulid,
                // What the billing numbers below cover - the UI quotes this
                // instead of labelling them with the semester (T23).
                'billing_scope' => $billingPeriod,
                'billing_label' => $billingLabel,
            ],
            // What the watchlist conditions above were measured against - the
            // tiles quote these, and T24 may turn them into per-unit config.
            'thresholds' => [
                'kkm' => WatchlistService::KKM,
                'min_alpa' => WatchlistService::HIGH_ABSENTEEISM_ALPA,
                'grade_drop' => WatchlistService::GRADE_DROP_POINTS,
            ],
            'scope' => [
                'is_central' => ! $user?->isUnitScoped(),
                'unit_count' => $units->count(),
                'unit_label' => $user?->isUnitScoped() ? $user->school_unit?->label : null,
            ],
            'kpi' => [
                'students_active' => $grand['students_active'],
                'students_new' => $grand['students_new'],
                'enrolled_students' => $grand['enrolled_students'],
                'students_total' => $students->count(),
                'classrooms' => $grand['classrooms'],
                'teachers' => $grand['teachers'],
                'billing' => $grand['billing'],
                'attendance' => $grand['attendance'],
                'attendance_today' => $grand['attendance_today'],
                'achievements' => $grand['achievements'],
                'grades' => $grand['grades'],
                'points' => $grand['points'],
                'extracurriculars' => $grand['extracurriculars'],
            ],
            'units' => $unitsData,
            'alerts' => $alerts,
        ]);
    }

    /** @return array{students_active:int,students_new:int,enrolled_students:int,classrooms:int,teachers:int,billing:array,attendance:array,attendance_today:array,achievements:array,grades:array,points:array,extracurriculars:array} */
    private function emptyGrandTotals(): array
    {
        return [
            'students_active' => 0,
            'students_new' => 0,
            'enrolled_students' => 0,
            'classrooms' => 0,
            'teachers' => 0,
            'billing' => [
                'total_billed' => 0.0,
                'total_paid' => 0.0,
                'total_outstanding' => 0.0,
                'collection_rate' => 0,
                'bill_count' => 0,
                'overdue_bills' => 0,
                'overdue_amount' => 0.0,
                'unpaid_count' => 0,
                'partial_count' => 0,
            ],
            'attendance' => ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'rate' => null, 'total' => 0],
            'attendance_today' => ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'rate' => null, 'total' => 0],
            'achievements' => ['verified' => 0, 'pending' => 0, 'siswa' => 0, 'guru' => 0],
            'grades' => ['students_graded' => 0, 'average' => 0, 'below_kkm' => 0, 'declined' => 0],
            'points' => ['merit_records' => 0, 'violation_records' => 0, 'violation_students' => 0],
            'extracurriculars' => ['activities' => 0, 'members' => 0],
        ];
    }

    private function attendanceTallies(Collection $rows): array
    {
        return [
            'hadir' => (int) $rows->where('attendance_status', 'hadir')->sum('total'),
            'sakit' => (int) $rows->where('attendance_status', 'sakit')->sum('total'),
            'izin' => (int) $rows->where('attendance_status', 'izin')->sum('total'),
            'alpa' => (int) $rows->where('attendance_status', 'alpa')->sum('total'),
        ];
    }

    /**
     * The watchlist the dashboard shows: conditions worth a staff glance,
     * each with a severity and - for the central admin - how it splits per unit.
     *
     * Money alerts read the bill buckets over EVERY unit (a central admin's
     * scope includes inactive units, and an alert must name them), while the
     * per-unit rows and grand totals above only ever walk the active unit
     * list - the same split the hydrated version kept.
     *
     * @param  Collection  $billBuckets  stdClass rows keyed by nothing: unit_id, overdue_bills, debtor_students
     * @param  Collection  $watch  WatchlistService rows keyed by student id
     * @return array<int, array{id:string,label:string,detail:string,count:int,severity:string,href:?string,units:array<int,array{code:string,label:string,count:int}>}>
     */
    private function buildAlerts(
        Collection $billBuckets,
        Collection $enrollments,
        Collection $studentById,
        Collection $units,
        ?Term $term,
        Collection $achievementBuckets,
        Collection $watch,
        string $billingLabel,
        string $tagihanHref,
    ): array {
        $perUnit = function (Collection $grouped) use ($units) {
            return $units
                ->map(function (SchoolUnit $unit) use ($grouped) {
                    $count = $grouped->get($unit->id, 0);

                    return ['code' => $unit->code, 'label' => $unit->label, 'count' => (int) $count];
                })
                ->filter(fn ($row) => $row['count'] > 0)
                ->sortByDesc('count')
                ->values()
                ->all();
        };

        $unitOf = fn ($studentId) => $studentById->get($studentId)?->school_unit_id;

        $alerts = [];

        // 1. Overdue bills
        $overdueByUnit = $billBuckets->pluck('overdue_bills', 'unit_id');
        $alerts[] = $this->alert(
            id: 'overdue',
            label: 'Tagihan lewat jatuh tempo',
            detail: "Masih tersisa dan belum dilunasi setelah jatuh tempo - cakupan {$billingLabel}",
            count: (int) $overdueByUnit->sum(),
            severity: 'bad',
            href: $tagihanHref,
            grouped: $overdueByUnit,
            perUnit: $perUnit,
            units: $units,
        );

        // 2. Students with outstanding receivables
        $debtorsByUnit = $billBuckets->pluck('debtor_students', 'unit_id');
        $alerts[] = $this->alert(
            id: 'outstanding',
            label: 'Siswa dengan sisa piutang',
            detail: "Memiliki tagihan terbuka yang belum lunas - cakupan {$billingLabel}",
            count: (int) $debtorsByUnit->sum(),
            severity: 'warn',
            href: $tagihanHref,
            grouped: $debtorsByUnit,
            perUnit: $perUnit,
            units: $units,
        );

        // 3. High absenteeism (rollup alpa on current-year enrollment)
        $highAlpa = $watch->filter(fn (array $row) => in_array('absenteeism', $row['reasons'], true));
        $alerts[] = $this->alert(
            id: 'absenteeism',
            label: 'Siswa absensi tinggi',
            detail: sprintf('Alpa %d kali atau lebih di tahun ajaran berjalan', WatchlistService::HIGH_ABSENTEEISM_ALPA),
            count: $highAlpa->count(),
            severity: 'bad',
            href: '/admin/perhatian?reason=absenteeism',
            grouped: $highAlpa->map(fn (array $row) => $unitOf($row['student_id']))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 4. Grades below KKM (current term)
        if ($term) {
            $belowKkm = $watch->filter(fn (array $row) => in_array('below_kkm', $row['reasons'], true));
            $alerts[] = $this->alert(
                id: 'grades',
                label: 'Nilai akhir di bawah KKM',
                detail: sprintf('Rata-rata nilai akhir semester berjalan di bawah %d', WatchlistService::KKM),
                count: $belowKkm->count(),
                severity: 'warn',
                href: '/admin/perhatian?reason=below_kkm',
                grouped: $belowKkm->map(fn (array $row) => $unitOf($row['student_id']))->countBy(fn ($unitId) => $unitId),
                perUnit: $perUnit,
                units: $units,
            );
        }

        // 5. Pending achievement verifications - over every bucket, including
        // achievements no unit can be attributed to.
        $pendingTotal = (int) $achievementBuckets->where('status', 'pending')->sum('total');
        $pendingByUnit = new Collection;
        $achievementBuckets->where('status', 'pending')->each(function ($row) use ($pendingByUnit) {
            $unitId = $row->unit_id !== null ? (int) $row->unit_id : null;
            $pendingByUnit->put($unitId, ($pendingByUnit->get($unitId, 0)) + (int) $row->total);
        });
        $alerts[] = $this->alert(
            id: 'achievements',
            label: 'Prestasi menunggu verifikasi',
            detail: 'Diajukan namun belum diverifikasi staf',
            count: $pendingTotal,
            severity: 'warn',
            href: '/admin/prestasi',
            grouped: $pendingByUnit,
            perUnit: $perUnit,
            units: $units,
        );

        // 6. Point violations this term
        $violationStudents = $watch->filter(fn (array $row) => in_array('point_violation', $row['reasons'], true));
        $alerts[] = $this->alert(
            id: 'points',
            label: 'Siswa dengan pelanggaran poin',
            detail: 'Mencatat poin negatif pada semester berjalan',
            count: $violationStudents->count(),
            severity: 'bad',
            href: '/admin/poin',
            grouped: $violationStudents->map(fn (array $row) => $unitOf($row['student_id']))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 7. Active students with no active rombel this year
        $enrolledIds = $enrollments->pluck('student_id');
        $unplaced = $studentById
            ->filter(fn ($s) => $s->status === 'active' && ! $enrolledIds->contains($s->id));
        $alerts[] = $this->alert(
            id: 'unplaced',
            label: 'Siswa aktif belum ditempatkan di kelas',
            detail: 'Belum memiliki rombel aktif pada tahun ajaran berjalan',
            count: $unplaced->count(),
            severity: 'warn',
            href: '/admin/siswa?placement=none',
            grouped: $unplaced->map(fn ($s) => $s->school_unit_id)->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        return $alerts;
    }

    /**
     * @param  Collection<int, int>  $grouped  unitId => count
     */
    private function alert(
        string $id,
        string $label,
        string $detail,
        int $count,
        string $severity,
        ?string $href,
        Collection $grouped,
        callable $perUnit,
        Collection $units,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'detail' => $detail,
            'count' => $count,
            'severity' => $severity,
            'href' => $href,
            'units' => $perUnit($grouped),
        ];
    }
}
