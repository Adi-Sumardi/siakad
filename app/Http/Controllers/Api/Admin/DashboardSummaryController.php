<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Academic\GradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One payload for the whole executive dashboard (Ringkasan), both admin kinds.
 *
 * Scope is enforced the same way the rest of the admin area enforces it: the
 * unit list is narrowed to the caller's own unit when they are unit-scoped,
 * and every aggregation is built from that (already narrowed) unit list plus
 * visibleTo()-scoped rows, so one response shape cannot accidentally leak
 * another unit's numbers to a per-unit admin. The frontend decides how much
 * to render from role + the `scope.is_central` flag.
 */
class DashboardSummaryController extends Controller
{
    /** Assumed school KKM - a dashboard threshold, not a stored policy. */
    private const KKM = 70;

    /** An enrollment's rollup alpa count from which a child is worth flagging. */
    private const HIGH_ABSENTEEISM_ALPA = 5;

    /** A final-grade drop this large (in points) between terms counts as decline. */
    private const GRADE_DROP_POINTS = 5;

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = AcademicYear::current() ?? AcademicYear::latest('starts_on')->first();
        $term = $year?->activeTerm() ?? $year?->terms()->latest('starts_on')->first();
        $prevTerm = $this->previousTerm($term);

        $units = SchoolUnit::active()->ordered()
            ->when($user?->isUnitScoped(), fn ($q) => $q->where('id', $user->school_unit_id))
            ->get(['id', 'code', 'label', 'jenjang_group', 'sort_order']);

        // ---- Students -------------------------------------------------------
        $students = Student::query()
            ->visibleTo($user)
            ->get(['id', 'school_unit_id', 'status', 'entry_year_id']);
        $studentById = $students->keyBy('id');

        // Current-year active placements + rollups (alpa etc. live on the enrollment).
        $enrollments = $year
            ? Enrollment::where('academic_year_id', $year->id)
                ->where('status', 'active')
                ->whereIn('student_id', $studentById->keys())
                ->get(['id', 'student_id', 'absent_count', 'sick_count', 'permit_count'])
            : collect();

        $classrooms = $year
            ? Classroom::query()->visibleTo($user)
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->get(['id', 'school_unit_id'])
            : collect();

        $teachers = User::where('role', 'guru')
            ->where('is_active', true)
            ->whereNotNull('school_unit_id')
            ->when($user?->isUnitScoped(), fn ($q) => $q->where('school_unit_id', $user->school_unit_id))
            ->get(['id', 'school_unit_id']);

        // ---- Billing --------------------------------------------------------
        $bills = Bill::query()
            ->visibleTo($user)
            ->whereNotIn('status', ['cancelled'])
            ->with('student:id,school_unit_id')
            ->get(['id', 'student_id', 'total_amount', 'paid_amount', 'remaining_amount', 'status', 'due_date']);

        // ---- Attendance (term-to-date, aggregated in SQL) -------------------
        $scopeUnitIds = $units->pluck('id')->all();

        $attendanceRows = $term
            ? (new Collection(DB::table('attendance_records')
                ->join('students', 'students.id', '=', 'attendance_records.student_id')
                ->where('attendance_records.term_id', $term->id)
                ->where('attendance_records.record_status', 'recorded')
                ->whereIn('students.school_unit_id', $scopeUnitIds)
                ->selectRaw('students.school_unit_id, attendance_records.attendance_status, count(*) as total')
                ->groupBy('students.school_unit_id', 'attendance_records.attendance_status')
                ->get()))
            : collect();

        // "Kehadiran hari ini" - same per-session tally but restricted to today's date.
        $attendanceToday = (new Collection(DB::table('attendance_records')
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->where('attendance_records.occurred_on', now()->toDateString())
            ->where('attendance_records.record_status', 'recorded')
            ->whereIn('students.school_unit_id', $scopeUnitIds)
            ->selectRaw('students.school_unit_id, attendance_records.attendance_status, count(*) as total')
            ->groupBy('students.school_unit_id', 'attendance_records.attendance_status')
            ->get()));

        // ---- Grades (current + previous term, for KKM & decline alerts) -----
        $gradeTermIds = array_values(array_filter([$term?->id, $prevTerm?->id]));
        $grades = ! empty($gradeTermIds)
            ? Grade::query()->visibleTo($user)->whereIn('term_id', $gradeTermIds)
                ->join('students', 'students.id', '=', 'grades.student_id')
                ->get([
                    'grades.student_id', 'grades.subject_id', 'grades.term_id', 'grades.category',
                    'grades.score', 'students.school_unit_id',
                ])
            : collect();

        // ---- Achievements ---------------------------------------------------
        $achievements = Achievement::query()->visibleTo($user)
            ->with(['student:id,school_unit_id', 'teacher:id,school_unit_id'])
            ->get(['id', 'achiever_type', 'student_id', 'teacher_user_id', 'school_unit_id', 'status', 'tingkat']);

        // ---- Points (term-to-date) ------------------------------------------
        $points = $term
            ? (new Collection(DB::table('point_records')
                ->join('students', 'students.id', '=', 'point_records.student_id')
                ->where('point_records.term_id', $term->id)
                ->where('point_records.status', 'recorded')
                ->whereIn('students.school_unit_id', $scopeUnitIds)
                ->selectRaw('students.school_unit_id, point_records.student_id, point_records.type, point_records.points')
                ->get()))
            : collect();

        // ---- Extracurriculars ------------------------------------------------
        $extracurriculars = $year
            ? Extracurricular::query()->visibleTo($user)
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->get(['id', 'school_unit_id'])
            : collect();
        $ekskulIds = $extracurriculars->pluck('id');
        $ekskulMembers = $ekskulIds->isNotEmpty()
            ? ExtracurricularMember::whereIn('extracurricular_id', $ekskulIds)
                ->where('status', 'active')
                ->get(['extracurricular_id', 'student_id'])
            : collect();

        // ---- Per-unit + grand aggregates -------------------------------------
        $unitsData = [];
        $alerts = [];
        $grand = $this->emptyGrandTotals();

        foreach ($units as $unit) {
            $unitStudents = $students->where('school_unit_id', $unit->id);
            $unitEnrollments = $enrollments->filter(
                fn (Enrollment $e) => $studentById->get($e->student_id)?->school_unit_id === $unit->id,
            );
            $unitBills = $bills->filter(fn (Bill $b) => $b->student && $b->student->school_unit_id === $unit->id);
            $unitAtt = $attendanceRows->where('school_unit_id', $unit->id);
            $unitAchievements = $achievements->filter(fn (Achievement $a) => $this->achievementUnitId($a) === $unit->id);
            $unitPoints = $points->where('school_unit_id', $unit->id);
            $unitGrades = $grades->where('school_unit_id', $unit->id);
            $unitEkskuls = $extracurriculars->where('school_unit_id', $unit->id);
            $unitEkskulIds = $unitEkskuls->pluck('id');

            $billed = (float) $unitBills->sum('total_amount');
            $paid = (float) $unitBills->sum('paid_amount');
            $outstanding = (float) $unitBills->sum('remaining_amount');
            $overdueBills = $unitBills->filter(fn (Bill $b) => $b->isOverdue());

            $att = $this->attendanceTallies($unitAtt);
            $attTotal = array_sum($att);
            $attRate = $attTotal > 0 ? round(($att['hadir'] / $attTotal) * 100, 1) : null;

            $attToday = $this->attendanceTallies($attendanceToday->where('school_unit_id', $unit->id));
            $attTodayTotal = array_sum($attToday);
            $attTodayRate = $attTodayTotal > 0 ? round(($attToday['hadir'] / $attTodayTotal) * 100, 1) : null;

            $verified = $unitAchievements->where('status', 'verified');
            $pending = $unitAchievements->where('status', 'pending');

            $curScores = $this->finalScores($unitGrades, $term?->id);
            $belowKkmScores = $curScores->filter(fn (?float $s) => $s !== null && $s < self::KKM);
            $belowKkm = $belowKkmScores->count();

            $violationStudents = $unitPoints->filter(fn ($p) => (int) $p->points < 0)->pluck('student_id')->unique();

            // Watchlist counts per unit - the unit-scoped academic attention strip.
            $highAlpaIds = $unitEnrollments->filter(fn (Enrollment $e) => $e->absent_count >= self::HIGH_ABSENTEEISM_ALPA)->pluck('student_id');
            $prevUnitScores = $prevTerm ? $this->finalScores($unitGrades, $prevTerm->id) : collect();
            $declinedIds = $curScores->filter(function ($cur, $studentId) use ($prevUnitScores) {
                $prev = $prevUnitScores->get($studentId);

                return $prev !== null && $prev - $cur >= self::GRADE_DROP_POINTS;
            })->keys();
            $attentionIds = $highAlpaIds->merge($belowKkmScores->keys())->merge($declinedIds)->merge($violationStudents)->unique();

            $ekskulMemberCount = $ekskulMembers->filter(fn ($m) => $unitEkskulIds->contains($m->extracurricular_id))->count();

            $unitsData[] = [
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'unit_label' => $unit->label,
                'jenjang' => strtoupper($unit->jenjang_group),
                'students_active' => $unitStudents->where('status', 'active')->count(),
                'students_new' => $unitStudents->where('status', 'active')->where('entry_year_id', $year?->id)->count(),
                'enrolled_students' => $unitEnrollments->pluck('student_id')->unique()->count(),
                'classrooms' => $classrooms->where('school_unit_id', $unit->id)->count(),
                'teachers' => $teachers->where('school_unit_id', $unit->id)->count(),
                'billed' => $billed,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'collection_rate' => $billed > 0 ? round(($paid / $billed) * 100, 1) : 0,
                'overdue_bills' => $overdueBills->count(),
                'attendance_rate' => $attRate,
                'attendance_today' => $attToday,
                'attendance_today_rate' => $attTodayRate,
                'achievements' => $verified->count(),
                'achievements_pending' => $pending->count(),
                'grades_below_kkm' => $belowKkm,
                'grades_graded' => $curScores->count(),
                'grades_average' => $curScores->isEmpty() ? null : round($curScores->avg(), 1),
                'students_high_absenteeism' => $highAlpaIds->count(),
                'students_declined' => $declinedIds->count(),
                'students_needing_attention' => $attentionIds->count(),
                'extracurriculars' => $unitEkskuls->count(),
                'extracurricular_members' => $ekskulMemberCount,
                'violation_students' => $violationStudents->count(),
                'points_merit_records' => $unitPoints->filter(fn ($p) => (int) $p->points > 0)->count(),
                'points_violation_records' => $unitPoints->filter(fn ($p) => (int) $p->points < 0)->count(),
            ];

            // Grand totals
            $grand['students_active'] += $unitStudents->where('status', 'active')->count();
            $grand['students_new'] += $unitStudents->where('status', 'active')->where('entry_year_id', $year?->id)->count();
            $grand['enrolled_students'] += $unitEnrollments->pluck('student_id')->unique()->count();
            $grand['classrooms'] += $classrooms->where('school_unit_id', $unit->id)->count();
            $grand['teachers'] += $teachers->where('school_unit_id', $unit->id)->count();
            $grand['billing']['total_billed'] += $billed;
            $grand['billing']['total_paid'] += $paid;
            $grand['billing']['total_outstanding'] += $outstanding;
            $grand['billing']['bill_count'] += $unitBills->count();
            $grand['billing']['overdue_bills'] += $overdueBills->count();
            $grand['billing']['overdue_amount'] += (float) $overdueBills->sum('remaining_amount');
            $grand['billing']['unpaid_count'] += $unitBills->where('status', 'unpaid')->count();
            $grand['billing']['partial_count'] += $unitBills->where('status', 'partial')->count();
            foreach (['hadir', 'sakit', 'izin', 'alpa'] as $status) {
                $grand['attendance'][$status] += $att[$status];
                $grand['attendance_today'][$status] += $attToday[$status];
            }
            $grand['achievements']['verified'] += $verified->count();
            $grand['achievements']['pending'] += $pending->count();
            $grand['achievements']['siswa'] += $verified->filter(fn ($a) => $a->achiever_type === 'siswa' || empty($a->achiever_type))->count();
            $grand['achievements']['guru'] += $verified->where('achiever_type', 'guru')->count();
            $grand['grades']['below_kkm'] += $belowKkm;
            $grand['points']['violation_students'] += $violationStudents->count();
            $grand['points']['violation_records'] += $unitPoints->filter(fn ($p) => (int) $p->points < 0)->count();
            $grand['points']['merit_records'] += $unitPoints->filter(fn ($p) => (int) $p->points > 0)->count();
            $grand['extracurriculars']['activities'] += $unitEkskuls->count();
            $grand['extracurriculars']['members'] += $ekskulMemberCount;
        }

        // ---- Grade-wide average (of per-student final averages, current term)
        $grand['grades']['students_graded'] = 0;
        $grand['grades']['average'] = 0;
        if ($term) {
            $allCurScores = $this->finalScores($grades, $term->id);
            $allCurScores = $allCurScores->filter(fn ($s) => $s !== null);
            $grand['grades']['students_graded'] = $allCurScores->count();
            $grand['grades']['average'] = $allCurScores->isEmpty()
                ? 0
                : round($allCurScores->avg(), 1);
            $grand['grades']['below_kkm'] = $this->finalScores($grades, $term->id)
                ->filter(fn (?float $s) => $s !== null && $s < self::KKM)->count();

            $prevScores = $prevTerm ? $this->finalScores($grades, $prevTerm->id) : collect();
            $grand['grades']['declined'] = $this->countDeclined($allCurScores->map(fn ($s) => (float) $s), $prevScores->map(fn ($s) => (float) $s), self::GRADE_DROP_POINTS);
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
        $alerts = $this->buildAlerts(
            $bills, $enrollments, $studentById, $units, $term, $grades, $achievements, $points,
        );

        return response()->json([
            'period' => [
                'academic_year' => $year?->year,
                'term' => $term?->name,
                'term_label' => $term ? ucfirst($term->name).' '.$year?->year : null,
                'term_ulid' => $term?->ulid,
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

    /** The unit an achievement belongs to: its explicit unit, else via the student, else via the teacher. */
    private function achievementUnitId(Achievement $achievement): ?int
    {
        if ($achievement->school_unit_id) {
            return $achievement->school_unit_id;
        }

        return $achievement->student?->school_unit_id ?? $achievement->teacher?->school_unit_id ?? null;
    }

    /**
     * Per-student average final score for one term.
     *
     * A student sits many subjects, so the score is first finalized per
     * subject (GradeService weighting, null when a subject's categories are
     * incomplete) and then averaged across that student's complete subjects -
     * NOT collapsed across subjects, which would silently keep only the last
     * subject's data per category.
     *
     * @return Collection<int, float> keyed by student id, only students with at least one complete subject
     */
    private function finalScores(Collection $termGrades, ?int $termId): Collection
    {
        if (! $termId) {
            return collect();
        }

        return $termGrades
            ->where('term_id', $termId)
            ->groupBy(fn ($g) => $g->student_id.'|'.$g->subject_id)
            ->map(function (Collection $rows) {
                $scores = $rows->pluck('score', 'category')->map(fn ($s) => (float) $s);

                if (array_diff(array_keys(GradeService::WEIGHTS), $scores->keys()->all())) {
                    return null;
                }

                $total = 0.0;
                foreach (GradeService::WEIGHTS as $category => $weight) {
                    $total += (float) $scores[$category] * $weight;
                }

                return round($total, 2);
            })
            ->filter(fn ($final) => $final !== null)
            ->groupBy(fn ($final, string $key) => explode('|', $key)[0])
            ->map(fn (Collection $finals) => round($finals->avg(), 2));
    }

    /** How many students dropped at least $dropPoints between term averages. Both maps are studentId => float|null. */
    private function countDeclined(Collection $current, Collection $previous, float $dropPoints): int
    {
        return $current->filter(function ($cur, $studentId) use ($previous, $dropPoints) {
            $prev = $previous->get($studentId);

            return $cur !== null && $prev !== null && $prev - $cur >= $dropPoints;
        })->count();
    }

    private function previousTerm(?Term $term): ?Term
    {
        if (! $term) {
            return null;
        }

        $prev = Term::where('academic_year_id', $term->academic_year_id)
            ->where('starts_on', '<', $term->starts_on)
            ->orderByDesc('starts_on')
            ->first();

        if ($prev) {
            return $prev;
        }

        $prevYear = AcademicYear::where('starts_on', '<', $term->academicYear->starts_on)
            ->latest('starts_on')
            ->first();

        if (! $prevYear) {
            return null;
        }

        return $prevYear->terms()->where('name', $term->name)->first()
            ?? $prevYear->terms()->latest('starts_on')->first();
    }

    /**
     * The watchlist the dashboard shows: conditions worth a staff glance,
     * each with a severity and - for the central admin - how it splits per unit.
     *
     * @return array<int, array{id:string,label:string,detail:string,count:int,severity:string,href:?string,units:array<int,array{code:string,label:string,count:int}>}>
     */
    private function buildAlerts(
        Collection $bills,
        Collection $enrollments,
        Collection $studentById,
        Collection $units,
        ?Term $term,
        Collection $grades,
        Collection $achievements,
        Collection $points,
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
        $overdueBills = $bills->filter(fn (Bill $b) => $b->isOverdue());
        $alerts[] = $this->alert(
            id: 'overdue',
            label: 'Tagihan lewat jatuh tempo',
            detail: 'Masih tersisa dan belum dilunasi setelah tanggal jatuh tempo',
            count: $overdueBills->count(),
            severity: 'bad',
            href: '/admin/tagihan',
            grouped: $overdueBills->countBy(fn (Bill $b) => $b->student?->school_unit_id),
            perUnit: $perUnit,
            units: $units,
        );

        // 2. Students with outstanding receivables
        $debtorStudentIds = $bills->filter(fn (Bill $b) => $b->isOpen())->pluck('student_id')->unique();
        $alerts[] = $this->alert(
            id: 'outstanding',
            label: 'Siswa dengan sisa piutang',
            detail: 'Memiliki tagihan terbuka yang belum lunas',
            count: $debtorStudentIds->count(),
            severity: 'warn',
            href: '/admin/tagihan',
            grouped: $debtorStudentIds->map(fn ($id) => $unitOf($id))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 3. High absenteeism (rollup alpa on current-year enrollment)
        $highAlpa = $enrollments->filter(fn (Enrollment $e) => $e->absent_count >= self::HIGH_ABSENTEEISM_ALPA);
        $alerts[] = $this->alert(
            id: 'absenteeism',
            label: 'Siswa absensi tinggi',
            detail: sprintf('Alpa %d kali atau lebih di tahun ajaran berjalan', self::HIGH_ABSENTEEISM_ALPA),
            count: $highAlpa->count(),
            severity: 'bad',
            href: '/admin/laporan',
            grouped: $highAlpa->map(fn (Enrollment $e) => $unitOf($e->student_id))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 4. Grades below KKM (current term)
        if ($term) {
            $cur = $this->finalScores($grades, $term->id);
            $belowKkm = $cur->filter(fn (?float $s) => $s !== null && $s < self::KKM);
            $alerts[] = $this->alert(
                id: 'grades',
                label: 'Nilai akhir di bawah KKM',
                detail: sprintf('Rata-rata nilai akhir semester berjalan di bawah %d', self::KKM),
                count: $belowKkm->count(),
                severity: 'warn',
                href: '/admin/nilai',
                grouped: $belowKkm->keys()->map(fn ($id) => $unitOf($id))->countBy(fn ($unitId) => $unitId),
                perUnit: $perUnit,
                units: $units,
            );
        }

        // 5. Pending achievement verifications
        $pending = $achievements->where('status', 'pending');
        $alerts[] = $this->alert(
            id: 'achievements',
            label: 'Prestasi menunggu verifikasi',
            detail: 'Diajukan namun belum diverifikasi staf',
            count: $pending->count(),
            severity: 'warn',
            href: '/admin/prestasi',
            grouped: $pending->map(fn (Achievement $a) => $this->achievementUnitId($a))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 6. Point violations this term
        $violationStudents = $points->filter(fn ($p) => (int) $p->points < 0)->pluck('student_id')->unique();
        $alerts[] = $this->alert(
            id: 'points',
            label: 'Siswa dengan pelanggaran poin',
            detail: 'Mencatat poin negatif pada semester berjalan',
            count: $violationStudents->count(),
            severity: 'bad',
            href: '/admin/poin',
            grouped: $violationStudents->map(fn ($id) => $unitOf($id))->countBy(fn ($unitId) => $unitId),
            perUnit: $perUnit,
            units: $units,
        );

        // 7. Active students with no active rombel this year
        $enrolledIds = $enrollments->pluck('student_id');
        $unplaced = $studentById
            ->filter(fn (Student $s) => $s->status === 'active' && ! $enrolledIds->contains($s->id));
        $alerts[] = $this->alert(
            id: 'unplaced',
            label: 'Siswa aktif belum ditempatkan di kelas',
            detail: 'Belum memiliki rombel aktif pada tahun ajaran berjalan',
            count: $unplaced->count(),
            severity: 'warn',
            href: '/admin/kelas',
            grouped: $unplaced->map(fn (Student $s) => $s->school_unit_id),
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
