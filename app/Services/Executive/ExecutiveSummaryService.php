<?php

namespace App\Services\Executive;

use App\Http\Controllers\Api\Admin\DashboardSummaryController;
use App\Http\Requests\Admin\DashboardSummaryRequest;
use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Academic\WatchlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard eksekutif untuk Direktur Pendidikan dan BPH.
 *
 * Angka dasarnya diambil dari DashboardSummaryController (versi admin pusat),
 * jadi yang dilihat yayasan selalu sama dengan yang dilihat TU di unit. Di
 * atasnya ditambah empat hal yang belum ada di dashboard admin: umur
 * tunggakan, tren siswa per tahun ajaran, tren bulanan (kehadiran dan uang
 * masuk), dan kapan presensi terakhir diisi per unit.
 *
 * Hanya angka per unit. Tidak ada nama siswa di payload ini.
 */
class ExecutiveSummaryService
{
    /** Ambang "Perlu perhatian" ada di config/eksekutif.php (masih usulan). */
    private const MAX_ALERTS = 4;

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(
        private DashboardSummaryController $dashboard,
        private WatchlistService $watchlist,
    ) {}

    /**
     * Payload sesuai role yang membuka, opsional dipersempit ke satu unit.
     * $view dipakai admin pusat untuk pratinjau tampilan Direktur atau BPH.
     */
    public function forViewer(User $viewer, ?string $unitCode, ?string $view = null): ?array
    {
        $data = Cache::remember('eksekutif:data', (int) config('eksekutif.cache_seconds', 120), fn () => $this->collect());
        $role = $view ?? $viewer->role;

        $units = collect($data['units']);
        $selected = $unitCode ? $units->firstWhere('code', $unitCode) : null;

        if ($unitCode && ! $selected) {
            return null;
        }

        $scope = $selected ? collect([$selected]) : $units;
        $isBph = $role === 'bph';

        $alerts = $this->alerts($scope, $data['enrollment_trend'], $isBph);

        $payload = [
            'period' => $data['period'],
            'viewer' => [
                'role' => $role,
                'role_label' => User::ROLE_LABELS[$role] ?? $role,
                'is_preview' => $role !== $viewer->role,
            ],
            'units' => $units->map(fn ($u) => ['code' => $u['code'], 'label' => $u['label'], 'short' => $u['short']])->values(),
            'selected_unit' => $selected ? ['code' => $selected['code'], 'label' => $selected['label']] : null,
            'alerts' => array_slice($alerts, 0, self::MAX_ALERTS),
            'alerts_more' => max(0, count($alerts) - self::MAX_ALERTS),
            'thresholds' => [
                'attendance_warn' => $this->t('attendance_warn'),
                'attendance_crit' => $this->t('attendance_crit'),
                'collection_warn' => $this->t('collection_warn'),
                'collection_crit' => $this->t('collection_crit'),
                'kkm' => $data['kkm'],
            ],
            'pendidikan' => [
                'tiles' => $this->educationTiles($scope, $selected ? null : $data['kpi']),
                'per_unit' => $units->map(fn ($u) => $this->educationRow($u, $isBph))->values(),
                'attendance_trend' => $this->trendFor($data['attendance_trend'], $selected['code'] ?? null, 'rate'),
            ],
            'enrollment_trend' => $this->enrollmentFor($data['enrollment_trend'], $scope),
        ];

        // Direktur cukup satu angka keuangan (SPP terbayar, ada di
        // educationRow/tiles). Rincian uang hanya untuk BPH.
        if ($isBph) {
            $payload['keuangan'] = [
                'tiles' => $this->financeTiles($scope),
                'per_unit' => $units->map(fn ($u) => $this->financeRow($u))->values(),
                'cash_trend' => $this->trendFor($data['cash_trend'], $selected['code'] ?? null, 'amount'),
            ];
        }

        return $payload;
    }

    /** Semua angka mentah, dihitung sekali per periode cache untuk kedua role. */
    private function collect(): array
    {
        $summary = $this->adminSummary();
        $year = AcademicYear::current() ?? AcademicYear::latest('starts_on')->first();
        $today = Carbon::now('Asia/Jakarta')->startOfDay();

        $aging = $this->arrearsAging($today);
        $discounts = $this->discounts($year);
        $lastAttendance = $this->lastAttendanceDates();

        $units = collect($summary['units'])->map(function (array $u) use ($aging, $discounts, $lastAttendance, $today) {
            $id = $u['unit_id'];
            $last = $lastAttendance[$id] ?? null;
            $buckets = $aging[$id] ?? [0.0, 0.0, 0.0, 0.0];
            $disc = $discounts[$id] ?? ['discount' => 0.0, 'gross' => 0.0];

            return [
                'id' => $id,
                'code' => $u['unit_code'],
                'label' => $u['unit_label'],
                'short' => $this->shortLabel($u['unit_label']),
                'jenjang' => $u['jenjang'],
                'students_active' => $u['students_active'],
                'teachers' => $u['teachers'],
                'classrooms' => $u['classrooms'],
                'attendance_rate' => $u['attendance_rate'],
                'attendance_last_date' => $last,
                'attendance_missed_days' => $last ? $this->schoolDaysSince(Carbon::parse($last, 'Asia/Jakarta'), $today) : null,
                'grades_average' => $u['grades_average'],
                'grades_graded' => $u['grades_graded'],
                'grades_below_kkm' => $u['grades_below_kkm'],
                'violation_students' => $u['violation_students'],
                'achievements' => $u['achievements'],
                'needing_attention' => $u['students_needing_attention'],
                'billed' => $u['billed'],
                'paid' => $u['paid'],
                'collection_rate' => $u['collection_rate'],
                'arrears' => $buckets,
                'discount' => $disc['discount'],
                'gross' => $disc['gross'],
            ];
        })->values();

        return [
            'period' => [
                'academic_year' => $summary['period']['academic_year'],
                'term_label' => $summary['period']['term_label'],
                'billing_label' => $summary['period']['billing_label'],
                'generated_at' => now()->toIso8601String(),
            ],
            'kkm' => $summary['thresholds']['kkm'] ?? null,
            'kpi' => $summary['kpi'],
            'units' => $units->all(),
            'enrollment_trend' => $this->enrollmentTrend($year),
            'attendance_trend' => $this->monthlyAttendance($today, $units),
            'cash_trend' => $this->monthlyCash($today, $units),
        ];
    }

    /** Dashboard admin pusat, dijalankan dengan konteks admin sistem (seperti Yapinet). */
    private function adminSummary(): array
    {
        $system = (new User)->forceFill(['id' => 0, 'role' => 'admin', 'name' => 'Dashboard Eksekutif']);
        $request = DashboardSummaryRequest::create('/api/admin/dashboard/summary', 'GET');
        $request->setUserResolver(fn () => $system);

        return $this->dashboard->summary($request, $this->watchlist)->getData(true);
    }

    /**
     * Tunggakan = tagihan lewat jatuh tempo yang belum lunas, semua tahun ajaran.
     * Umur dihitung per tagihan dari tanggal jatuh tempo, hanya sisa yang belum
     * dibayar (cicilan sebagian tidak dihitung penuh).
     *
     * @return array<int, array{0: float, 1: float, 2: float, 3: float}>
     */
    private function arrearsAging(Carbon $today): array
    {
        $d30 = $today->copy()->subDays(30)->toDateString();
        $d60 = $today->copy()->subDays(60)->toDateString();
        $d90 = $today->copy()->subDays(90)->toDateString();

        return DB::table('bills')
            ->join('students', 'students.id', '=', 'bills.student_id')
            ->whereNull('students.deleted_at')
            ->whereIn('bills.status', ['unpaid', 'partial', 'overdue'])
            ->where('bills.remaining_amount', '>', 0)
            // Batas yang sama dengan $overdueEdge di DashboardSummaryController:
            // jatuh tempo hari ini sudah dihitung lewat (kelompok 0–30).
            ->where('bills.due_date', '<', $today->copy()->addDay()->toDateString())
            ->selectRaw(
                'students.school_unit_id as unit_id,'
                .' coalesce(sum(case when bills.due_date >= ? then bills.remaining_amount else 0 end), 0) as b0,'
                .' coalesce(sum(case when bills.due_date < ? and bills.due_date >= ? then bills.remaining_amount else 0 end), 0) as b1,'
                .' coalesce(sum(case when bills.due_date < ? and bills.due_date >= ? then bills.remaining_amount else 0 end), 0) as b2,'
                .' coalesce(sum(case when bills.due_date < ? then bills.remaining_amount else 0 end), 0) as b3',
                [$d30, $d30, $d60, $d60, $d90, $d90],
            )
            ->groupBy('students.school_unit_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->unit_id => [(float) $r->b0, (float) $r->b1, (float) $r->b2, (float) $r->b3]])
            ->all();
    }

    /** Potongan (diskon & beasiswa) yang tertulis di tagihan tahun ajaran berjalan. */
    private function discounts(?AcademicYear $year): array
    {
        if (! $year) {
            return [];
        }

        return DB::table('bills')
            ->join('students', 'students.id', '=', 'bills.student_id')
            ->whereNull('students.deleted_at')
            ->where('bills.academic_year_id', $year->id)
            ->whereNotIn('bills.status', ['cancelled', 'draft'])
            ->selectRaw('students.school_unit_id as unit_id, coalesce(sum(bills.discount_amount), 0) as discount, coalesce(sum(bills.subtotal), 0) as gross')
            ->groupBy('students.school_unit_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->unit_id => ['discount' => (float) $r->discount, 'gross' => (float) $r->gross]])
            ->all();
    }

    /** Tanggal presensi harian (masuk) terakhir yang terisi, per unit. */
    private function lastAttendanceDates(): array
    {
        return DB::table('daily_records')
            ->join('daily_sessions', 'daily_sessions.id', '=', 'daily_records.daily_session_id')
            ->where('daily_records.record_status', 'recorded')
            ->where('daily_sessions.type', 'masuk')
            ->selectRaw('daily_sessions.school_unit_id as unit_id, max(daily_records.date) as last_date')
            ->groupBy('daily_sessions.school_unit_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->unit_id => substr((string) $r->last_date, 0, 10)])
            ->all();
    }

    /** Hari sekolah (Senin–Jumat, bukan hari libur) setelah $last sampai kemarin. */
    private function schoolDaysSince(Carbon $last, Carbon $today): int
    {
        if ($last->gte($today)) {
            return 0;
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$last->copy()->addDay()->toDateString(), $today->copy()->subDay()->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $missed = 0;
        for ($day = $last->copy()->addDay(); $day->lt($today); $day->addDay()) {
            if (! $day->isWeekend() && ! $holidays->has($day->toDateString())) {
                $missed++;
            }
        }

        return $missed;
    }

    /**
     * Siswa terdaftar di kelas per tahun ajaran, tiga TA terakhir sampai TA
     * berjalan. Sumbernya penempatan kelas (enrollment), bukan status siswa,
     * karena hanya itu yang tersimpan untuk tahun-tahun lalu.
     */
    private function enrollmentTrend(?AcademicYear $current): array
    {
        if (! $current) {
            return ['years' => [], 'units' => []];
        }

        $years = AcademicYear::query()
            ->where('starts_on', '<=', $current->starts_on)
            ->orderByDesc('starts_on')
            ->limit(3)
            ->get(['id', 'year'])
            ->reverse()
            ->values();

        $counts = DB::table('enrollments')
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereNull('students.deleted_at')
            ->whereIn('enrollments.academic_year_id', $years->pluck('id')->all())
            ->where('enrollments.status', '!=', 'left')
            ->selectRaw('classrooms.school_unit_id as unit_id, enrollments.academic_year_id as year_id, count(distinct enrollments.student_id) as total')
            ->groupBy('classrooms.school_unit_id', 'enrollments.academic_year_id')
            ->get();

        $byUnit = [];
        foreach ($counts as $row) {
            $byUnit[(int) $row->unit_id][(int) $row->year_id] = (int) $row->total;
        }

        return [
            'years' => $years->map(fn ($y) => ['id' => $y->id, 'label' => $y->year])->all(),
            'units' => $byUnit,
        ];
    }

    /**
     * Kehadiran harian per bulan, enam bulan terakhir. Dijumlah per tanggal di
     * SQL lalu digulung ke bulan di PHP, supaya sama di SQLite dan Postgres.
     */
    private function monthlyAttendance(Carbon $today, Collection $units): array
    {
        $from = $today->copy()->startOfMonth()->subMonths(5);

        $rows = DB::table('daily_records')
            ->join('daily_sessions', 'daily_sessions.id', '=', 'daily_records.daily_session_id')
            ->where('daily_records.record_status', 'recorded')
            ->where('daily_sessions.type', 'masuk')
            ->where('daily_records.date', '>=', $from->toDateString())
            ->whereIn('daily_sessions.school_unit_id', $units->pluck('id')->all())
            ->selectRaw('daily_sessions.school_unit_id as unit_id, daily_records.date as day, daily_records.attendance_status as status, count(*) as total')
            ->groupBy('daily_sessions.school_unit_id', 'daily_records.date', 'daily_records.attendance_status')
            ->get();

        $codeById = $units->pluck('code', 'id');
        $tally = [];
        foreach ($rows as $row) {
            $month = substr((string) $row->day, 0, 7);
            foreach (['_all', $codeById[(int) $row->unit_id] ?? null] as $key) {
                if ($key === null) {
                    continue;
                }
                $tally[$key][$month]['total'] = ($tally[$key][$month]['total'] ?? 0) + (int) $row->total;
                if ($row->status === 'hadir') {
                    $tally[$key][$month]['hadir'] = ($tally[$key][$month]['hadir'] ?? 0) + (int) $row->total;
                }
            }
        }

        $out = [];
        foreach ($tally as $key => $months) {
            foreach ($months as $month => $t) {
                $out[$key][$month] = $t['total'] > 0 ? round(($t['hadir'] ?? 0) / $t['total'] * 100, 1) : null;
            }
        }

        return ['months' => $this->monthKeys($from, 6), 'series' => $out];
    }

    /**
     * Uang masuk per bulan (pembayaran selesai), enam bulan terakhir. Hanya
     * alokasi yang benar-benar melunasi tagihan (applies_to_bill), sama dengan
     * laporan rekonsiliasi.
     */
    private function monthlyCash(Carbon $today, Collection $units): array
    {
        $from = $today->copy()->startOfMonth()->subMonths(5);

        $rows = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('bills', 'bills.id', '=', 'payment_allocations.bill_id')
            ->join('students', 'students.id', '=', 'bills.student_id')
            ->where('payments.status', 'completed')
            ->where('payment_allocations.applies_to_bill', true)
            // paid_at disimpan UTC; batas bulan dihitung dalam WIB.
            ->where('payments.paid_at', '>=', $from->copy()->utc()->toDateTimeString())
            ->whereIn('students.school_unit_id', $units->pluck('id')->all())
            ->selectRaw('students.school_unit_id as unit_id, '.$this->jakartaMonth('payments.paid_at').' as month, sum(payment_allocations.amount) as total')
            ->groupByRaw('students.school_unit_id, '.$this->jakartaMonth('payments.paid_at'))
            ->get();

        $codeById = $units->pluck('code', 'id');
        $out = [];
        foreach ($rows as $row) {
            foreach (['_all', $codeById[(int) $row->unit_id] ?? null] as $key) {
                if ($key !== null) {
                    $out[$key][$row->month] = ($out[$key][$row->month] ?? 0) + (float) $row->total;
                }
            }
        }

        return ['months' => $this->monthKeys($from, 6), 'series' => $out];
    }

    /** 'YYYY-MM' dari timestamp UTC, digeser ke WIB (UTC+7). */
    private function jakartaMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column} + interval '7 hours', 'YYYY-MM')",
            'mysql', 'mariadb' => "date_format(date_add({$column}, interval 7 hour), '%Y-%m')",
            default => "strftime('%Y-%m', {$column}, '+7 hours')",
        };
    }

    /** @return array<int, array{key: string, label: string}> */
    private function monthKeys(Carbon $from, int $count): array
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $m = $from->copy()->addMonths($i);
            $keys[] = ['key' => $m->format('Y-m'), 'label' => self::MONTHS[$m->month - 1]];
        }

        return $keys;
    }

    private function trendFor(array $trend, ?string $unitCode, string $field): array
    {
        $series = $trend['series'][$unitCode ?? '_all'] ?? [];

        return array_map(fn ($m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            $field => $series[$m['key']] ?? ($field === 'amount' ? 0 : null),
        ], $trend['months']);
    }

    private function enrollmentFor(array $trend, Collection $scope): array
    {
        $rows = $scope
            ->map(fn ($u) => [
                'code' => $u['code'],
                'label' => $u['label'],
                'counts' => array_map(fn ($y) => $trend['units'][$u['id']][$y['id']] ?? 0, $trend['years']),
            ])->values()->all();

        $totals = array_map(
            fn ($i) => array_sum(array_column(array_column($rows, 'counts'), $i)),
            array_keys($trend['years']),
        );

        return [
            'years' => array_column($trend['years'], 'label'),
            'units' => $rows,
            'totals' => $totals,
        ];
    }

    private function educationTiles(Collection $scope, ?array $kpi): array
    {
        $students = (int) $scope->sum('students_active');
        $graded = (int) $scope->sum('grades_graded');
        $violations = (int) $scope->sum('violation_students');
        $billed = (float) $scope->sum('billed');

        if ($kpi) {
            $attendance = $kpi['attendance']['rate'] ?? null;
            $average = ($kpi['grades']['students_graded'] ?? 0) > 0 ? $kpi['grades']['average'] : null;
        } else {
            $one = $scope->first();
            $attendance = $one['attendance_rate'];
            $average = $one['grades_average'];
        }

        return [
            'students_active' => $students,
            'teachers' => (int) $scope->sum('teachers'),
            'attendance_rate' => $attendance,
            'grades_average' => $average,
            'grades_graded_pct' => $this->pct($graded, $students),
            'violation_students' => $violations,
            'violation_pct' => $this->pct($violations, $students),
            'achievements' => (int) $scope->sum('achievements'),
            'needing_attention' => (int) $scope->sum('needing_attention'),
            'collection_rate' => $billed > 0 ? round($scope->sum('paid') / $billed * 100, 1) : null,
        ];
    }

    private function educationRow(array $u, bool $brief): array
    {
        $gradedPct = $this->pct($u['grades_graded'], $u['students_active']);
        $row = [
            'code' => $u['code'],
            'label' => $u['label'],
            'short' => $u['short'],
            'students_active' => $u['students_active'],
            'attendance_rate' => $u['attendance_rate'],
            'grades_average' => $u['grades_average'],
            'needing_attention' => $u['needing_attention'],
            'data_complete' => [
                'attendance' => $this->attendanceComplete($u),
                'grades' => $gradedPct !== null && $gradedPct >= $this->t('grades_coverage_min'),
            ],
        ];

        if ($brief) {
            return $row;
        }

        return $row + [
            'teachers' => $u['teachers'],
            'students_per_teacher' => $u['teachers'] > 0 ? round($u['students_active'] / $u['teachers'], 1) : null,
            'attendance_last_date' => $u['attendance_last_date'],
            'attendance_missed_days' => $u['attendance_missed_days'],
            'grades_graded_pct' => $gradedPct,
            'grades_below_kkm' => $u['grades_below_kkm'],
            'grades_below_kkm_pct' => $this->pct($u['grades_below_kkm'], $u['grades_graded']),
            'violation_students' => $u['violation_students'],
            'violation_pct' => $this->pct($u['violation_students'], $u['students_active']),
            'achievements' => $u['achievements'],
            'collection_rate' => $u['billed'] > 0 ? $u['collection_rate'] : null,
        ];
    }

    private function financeTiles(Collection $scope): array
    {
        $billed = (float) $scope->sum('billed');
        $arrears = $scope->reduce(fn ($carry, $u) => array_map(fn ($a, $b) => $a + $b, $carry, $u['arrears']), [0.0, 0.0, 0.0, 0.0]);
        $arrearsTotal = array_sum($arrears);
        $gross = (float) $scope->sum('gross');

        return [
            'billed' => $billed,
            'paid' => (float) $scope->sum('paid'),
            'collection_rate' => $billed > 0 ? round($scope->sum('paid') / $billed * 100, 1) : null,
            'arrears_total' => $arrearsTotal,
            'arrears_over_90' => $arrears[3],
            'arrears_over_90_pct' => $this->pct($arrears[3], $arrearsTotal),
            'discount' => (float) $scope->sum('discount'),
            'discount_pct' => $this->pct($scope->sum('discount'), $gross),
        ];
    }

    private function financeRow(array $u): array
    {
        return [
            'code' => $u['code'],
            'label' => $u['label'],
            'short' => $u['short'],
            'billed' => $u['billed'],
            'paid' => $u['paid'],
            'collection_rate' => $u['billed'] > 0 ? $u['collection_rate'] : null,
            'arrears' => $u['arrears'],
            'arrears_total' => array_sum($u['arrears']),
            'discount' => $u['discount'],
        ];
    }

    /**
     * "Perlu perhatian": kalimat siap baca, yang kritis lebih dulu. Direktur
     * melihat sisi pendidikan, BPH sisi keuangan; turunnya jumlah siswa
     * muncul untuk keduanya.
     *
     * @return array<int, array{tone: string, title: string, body: string, unit_code: string}>
     */
    private function alerts(Collection $scope, array $enrollment, bool $isBph): array
    {
        $alerts = [];

        foreach ($scope as $u) {
            if ($u['students_active'] === 0) {
                continue;
            }

            if ($isBph) {
                $this->financeAlerts($u, $alerts);
            } else {
                $this->educationAlerts($u, $alerts);
            }

            $counts = array_map(fn ($y) => $enrollment['units'][$u['id']][$y['id']] ?? 0, $enrollment['years']);
            $this->enrollmentAlert($u, $counts, array_column($enrollment['years'], 'label'), $alerts);
        }

        usort($alerts, fn ($a, $b) => [$a['tone'] === 'kritis' ? 0 : 1, -$a['weight']] <=> [$b['tone'] === 'kritis' ? 0 : 1, -$b['weight']]);

        return array_map(fn ($a) => array_diff_key($a, ['weight' => true]), $alerts);
    }

    private function educationAlerts(array $u, array &$alerts): void
    {
        if (! $this->attendanceComplete($u)) {
            $alerts[] = $this->alert('waspada', $u, 50,
                $u['attendance_last_date']
                    ? "Presensi {$u['label']} belum diisi sejak ".$this->dateLabel($u['attendance_last_date'])
                    : "Presensi harian {$u['label']} belum pernah diisi",
                'Angka kehadiran unit ini belum bisa dipakai untuk menilai sampai presensi diisi lagi.',
            );
        } elseif ($u['attendance_rate'] !== null && $u['attendance_rate'] < $this->t('attendance_warn')) {
            $critical = $u['attendance_rate'] < $this->t('attendance_crit');
            $alerts[] = $this->alert($critical ? 'kritis' : 'waspada', $u, 100 - $u['attendance_rate'],
                "Kehadiran {$u['label']} hanya ".$this->fmtPct($u['attendance_rate']),
                'Batas wajar '.$this->fmtPct($critical ? $this->t('attendance_crit') : $this->t('attendance_warn')).'. Perlu dibicarakan dengan kepala sekolah.',
            );
        }

        $gradedPct = $this->pct($u['grades_graded'], $u['students_active']);
        $belowPct = $this->pct($u['grades_below_kkm'], $u['grades_graded']);
        if ($gradedPct !== null && $gradedPct >= $this->t('grades_coverage_min') && $belowPct !== null && $belowPct >= $this->t('below_kkm_warn')) {
            $alerts[] = $this->alert('waspada', $u, $belowPct,
                "{$u['grades_below_kkm']} siswa {$u['label']} nilainya di bawah KKM",
                $this->fmtPct($belowPct).' dari siswa yang sudah punya nilai akhir semester ini.',
            );
        }

        $violationPct = $this->pct($u['violation_students'], $u['students_active']);
        if ($violationPct !== null && $violationPct >= $this->t('violation_warn')) {
            $alerts[] = $this->alert('waspada', $u, $violationPct,
                "{$u['violation_students']} siswa {$u['label']} punya catatan pelanggaran",
                $this->fmtPct($violationPct).' dari siswa aktif, semester ini.',
            );
        }
    }

    private function financeAlerts(array $u, array &$alerts): void
    {
        if ($u['billed'] > 0 && $u['collection_rate'] < $this->t('collection_warn')) {
            $critical = $u['collection_rate'] < $this->t('collection_crit');
            $alerts[] = $this->alert($critical ? 'kritis' : 'waspada', $u, 100 - $u['collection_rate'],
                "SPP {$u['label']} baru terbayar ".$this->fmtPct($u['collection_rate']),
                'Rp '.$this->fmtMoney($u['billed'] - $u['paid']).' dari tagihan tahun ajaran ini belum masuk.',
            );
        }

        $total = array_sum($u['arrears']);
        $oldPct = $this->pct($u['arrears'][3], $total);
        if ($oldPct !== null && $oldPct >= $this->t('old_arrears_warn')) {
            $alerts[] = $this->alert($oldPct >= $this->t('old_arrears_crit') ? 'kritis' : 'waspada', $u, $oldPct,
                "Tunggakan lama {$u['label']}: Rp ".$this->fmtMoney($u['arrears'][3]),
                'Sudah lebih dari 90 hari, '.$this->fmtPct($oldPct).' dari seluruh tunggakan unit. Berisiko tidak tertagih.',
            );
        }
    }

    /** @param  array<int, int>  $counts  oldest → newest */
    private function enrollmentAlert(array $u, array $counts, array $labels, array &$alerts): void
    {
        $n = count($counts);
        if ($n < 2 || $counts[$n - 2] === 0 || $counts[$n - 1] >= $counts[$n - 2]) {
            return;
        }

        $twoYears = $n >= 3 && $counts[0] > 0 && $counts[1] < $counts[0];
        $alerts[] = $this->alert($twoYears ? 'kritis' : 'waspada', $u, $counts[$n - 2] - $counts[$n - 1],
            "Jumlah siswa {$u['label']} turun".($twoYears ? ' dua tahun berturut-turut' : ''),
            implode(' → ', array_map(fn ($c, $l) => "{$c} ({$l})", array_slice($counts, $twoYears ? 0 : $n - 2), array_slice($labels, $twoYears ? 0 : $n - 2))).' siswa.',
        );
    }

    private function alert(string $tone, array $u, float $weight, string $title, string $body): array
    {
        return ['tone' => $tone, 'title' => $title, 'body' => $body, 'unit_code' => $u['code'], 'weight' => $weight];
    }

    private function t(string $key): float
    {
        return (float) config("eksekutif.thresholds.{$key}");
    }

    private function attendanceComplete(array $u): bool
    {
        return $u['attendance_missed_days'] !== null && $u['attendance_missed_days'] <= $this->t('stale_attendance_days');
    }

    private function pct(float|int $part, float|int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private function fmtPct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',').'%';
    }

    private function fmtMoney(float $value): string
    {
        if ($value >= 1_000_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000_000, 2, ',', '.'), '0'), ',').' miliar';
        }
        if ($value >= 1_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').' juta';
        }

        return number_format($value, 0, ',', '.');
    }

    private function dateLabel(string $date): string
    {
        return Carbon::parse($date)->locale('id')->translatedFormat('j F Y');
    }

    /**
     * Label grafik yang sempit: "SDI Al Azhar 13 Rawamangun" → "SDI 13",
     * "RA Sakinah Kebayoran Baru" → "RA Sakinah". Nama lokasi dibuang.
     */
    private function shortLabel(string $label): string
    {
        $words = preg_split('/\s+/', trim(str_ireplace(['Al Azhar', 'Al-Azhar', 'Playgroup'], [' ', ' ', 'PG'], $label)));

        foreach ($words as $i => $word) {
            if (ctype_digit($word)) {
                return implode(' ', array_slice($words, 0, $i + 1));
            }
        }

        return implode(' ', array_slice($words, 0, 2));
    }
}
