<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\PointRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardSummaryController is the aggregation behind the new executive
 * dashboard. The scope rules are the same as DashboardChartController - a
 * unit-scoped admin sees only their own unit's rows and labels, a central
 * admin sees every unit - but the payload is the whole dashboard at once, so
 * these assertions cover both the unit LIST and the aggregated KPI numbers.
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function year(): AcademicYear
    {
        return AcademicYear::create([
            'year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true,
        ]);
    }

    private function unit(string $code, string $label, string $jenjang): SchoolUnit
    {
        return SchoolUnit::create(['code' => $code, 'label' => $label, 'jenjang_group' => $jenjang, 'is_active' => true]);
    }

    private function studentWithBill(AcademicYear $year, SchoolUnit $unit, string $nis, float $amount): Student
    {
        $student = Student::create([
            'nama_lengkap' => "Siswa {$nis}", 'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id, 'entry_year_id' => $year->id, 'nis' => $nis, 'status' => 'active',
        ]);

        $sppType = FeeType::create(['code' => "spp-{$nis}", 'name' => "SPP {$nis}", 'recurrence' => 'monthly']);
        Bill::create([
            'bill_number' => "SPP/2026/09/{$nis}", 'dedup_key' => "spp:2026:09:{$student->id}",
            'description' => 'SPP September', 'student_id' => $student->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $sppType->id, 'subtotal' => $amount, 'total_amount' => $amount,
            'remaining_amount' => $amount, 'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        return $student;
    }

    public function test_unit_admin_summary_only_lists_own_unit_and_only_its_totals(): void
    {
        $year = $this->year();
        $sd = $this->unit('SD-13', 'SD Islam Al Azhar 13', 'sd');
        $smp = $this->unit('SMP-12', 'SMP Islam Al Azhar 12', 'smp');

        $this->studentWithBill($year, $sd, '000001', 500000);
        $this->studentWithBill($year, $smp, '000002', 700000);

        $sdAdmin = User::create([
            'name' => 'Admin SD', 'email' => 'sd-summary@example.com', 'phone' => '081111111201',
            'role' => 'admin_unit', 'school_unit_id' => $sd->id, 'is_active' => true,
        ]);

        $body = $this->actingAs($sdAdmin)->getJson('/api/admin/dashboard/summary')->assertOk()->json();

        $unitCodes = collect($body['units'])->pluck('unit_code');
        $this->assertContains('SD-13', $unitCodes);
        $this->assertNotContains('SMP-12', $unitCodes);

        $this->assertFalse($body['scope']['is_central']);
        $this->assertSame(1, $body['kpi']['students_active']);
        $this->assertSame(1, $body['kpi']['billing']['bill_count']);
        $this->assertSame(500000.0, (float) $body['kpi']['billing']['total_outstanding']);
        $this->assertSame(1, collect($body['alerts'])->firstWhere('id', 'outstanding')['count']);
        $this->assertSame(0, collect($body['alerts'])->firstWhere('id', 'overdue')['count']);
    }

    public function test_central_admin_summary_lists_every_unit_and_aggregates_across_them(): void
    {
        $year = $this->year();
        $sd = $this->unit('SD-13', 'SD Islam Al Azhar 13', 'sd');
        $smp = $this->unit('SMP-12', 'SMP Islam Al Azhar 12', 'smp');

        $this->studentWithBill($year, $sd, '000003', 500000);
        $this->studentWithBill($year, $smp, '000004', 700000);

        $admin = User::create([
            'name' => 'Pusat', 'email' => 'pusat-summary@example.com', 'phone' => '081111111202',
            'role' => 'admin', 'is_active' => true,
        ]);

        $body = $this->actingAs($admin)->getJson('/api/admin/dashboard/summary')->assertOk()->json();

        $this->assertTrue($body['scope']['is_central']);
        $this->assertCount(2, $body['units']);
        $this->assertSame(2, $body['kpi']['students_active']);
        $this->assertSame(1200000.0, (float) $body['kpi']['billing']['total_outstanding']);
        $this->assertSame(2, $body['kpi']['billing']['bill_count']);
        $this->assertSame(2, collect($body['alerts'])->firstWhere('id', 'outstanding')['count']);
        $this->assertCount(2, collect($body['alerts'])->firstWhere('id', 'outstanding')['units']);

        // Academic-focus keys (kehadiran hari ini + rata-rata nilai per unit).
        $this->assertArrayHasKey('attendance_today', $body['kpi']);
        $this->assertNull($body['kpi']['attendance_today']['rate']);
        foreach ($body['units'] as $unit) {
            $this->assertArrayHasKey('attendance_today', $unit);
            $this->assertArrayHasKey('attendance_today_rate', $unit);
            $this->assertNull($unit['attendance_today_rate']);
            $this->assertSame(0, $unit['grades_graded']);
            $this->assertNull($unit['grades_average']);
            $this->assertSame(0, $unit['students_high_absenteeism']);
            $this->assertSame(0, $unit['students_declined']);
            $this->assertSame(0, $unit['students_needing_attention']);
            $this->assertSame(0, $unit['points_merit_records']);
            $this->assertSame(0, $unit['points_violation_records']);
        }
    }

    /**
     * T23: the money numbers are scoped to the running academic year by
     * default, so a leftover open bill from a previous year cannot colour
     * this year's overview - and the payload says which period it used.
     */
    public function test_billing_numbers_are_scoped_to_the_running_year_by_default(): void
    {
        $year = $this->year();
        $oldYear = AcademicYear::create([
            'year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30',
        ]);
        $sd = $this->unit('SD-13', 'SD Islam Al Azhar 13', 'sd');

        $this->studentWithBill($year, $sd, '000010', 500000);
        $this->studentWithBill($oldYear, $sd, '000011', 300000);

        $admin = User::create([
            'name' => 'Pusat', 'email' => 'pusat-billing-period@example.com', 'phone' => '081111111203',
            'role' => 'admin', 'is_active' => true,
        ]);

        $body = $this->actingAs($admin)->getJson('/api/admin/dashboard/summary')->assertOk()->json();

        $this->assertSame('year', $body['period']['billing_scope']);
        $this->assertSame('TA 2026/2027', $body['period']['billing_label']);
        $this->assertSame(1, $body['kpi']['billing']['bill_count']);
        $this->assertSame(500000.0, (float) $body['kpi']['billing']['total_outstanding']);
        $this->assertSame(1, collect($body['alerts'])->firstWhere('id', 'outstanding')['count']);
        // Money alerts land on the tagihan list pre-scoped to the same year,
        // so the counts line up on arrival.
        $this->assertSame('/admin/tagihan?year=2026%2F2027', collect($body['alerts'])->firstWhere('id', 'overdue')['href']);
    }

    public function test_billing_period_all_restores_the_all_time_numbers(): void
    {
        $year = $this->year();
        $oldYear = AcademicYear::create([
            'year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30',
        ]);
        $sd = $this->unit('SD-13', 'SD Islam Al Azhar 13', 'sd');

        $this->studentWithBill($year, $sd, '000012', 500000);
        $this->studentWithBill($oldYear, $sd, '000013', 300000);

        $admin = User::create([
            'name' => 'Pusat', 'email' => 'pusat-billing-all@example.com', 'phone' => '081111111204',
            'role' => 'admin', 'is_active' => true,
        ]);

        $body = $this->actingAs($admin)->getJson('/api/admin/dashboard/summary?billing_period=all')->assertOk()->json();

        $this->assertSame('all', $body['period']['billing_scope']);
        $this->assertSame('Semua periode', $body['period']['billing_label']);
        $this->assertSame(2, $body['kpi']['billing']['bill_count']);
        $this->assertSame(800000.0, (float) $body['kpi']['billing']['total_outstanding']);
        $this->assertSame(2, collect($body['alerts'])->firstWhere('id', 'outstanding')['count']);
        $this->assertSame('/admin/tagihan', collect($body['alerts'])->firstWhere('id', 'overdue')['href']);
    }

    public function test_billing_period_must_be_a_known_value(): void
    {
        $this->year();

        $admin = User::create([
            'name' => 'Pusat', 'email' => 'pusat-billing-invalid@example.com', 'phone' => '081111111205',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/dashboard/summary?billing_period=semester')
            ->assertStatus(422)
            ->assertInvalid('billing_period');
    }

    /**
     * The SQL-aggregation rewrite's parity contract: watchlist-grade data +
     * mixed bills (partial / overdue / cancelled) must produce the exact
     * numbers the hydrated version produced - grades still rounded through
     * WatchlistService in PHP, money summed in SQL.
     */
    public function test_sql_aggregation_matches_the_watchlist_and_billing_semantics(): void
    {
        $year = $this->year();
        $sd = $this->unit('SD-13', 'SD Islam Al Azhar 13', 'sd');
        $admin = User::create(['name' => 'Pusat', 'email' => 'pusat@yapinet.id', 'role' => 'admin', 'is_active' => true, 'activated_at' => now()]);

        $term = Term::create(['academic_year_id' => $year->id, 'name' => 'ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $kelas = Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 5, 'name' => '5A']);
        $mapel = Subject::create(['school_unit_id' => $sd->id, 'code' => 'MTK', 'name' => 'Matematika']);

        $enroll = fn (Student $s, int $alpa = 0) => Enrollment::create([
            'student_id' => $s->id, 'classroom_id' => $kelas->id, 'academic_year_id' => $year->id,
            'status' => 'active', 'joined_on' => '2026-07-01', 'absent_count' => $alpa,
        ]);
        $grade = fn (Student $s, Term $t, string $cat, float $score) => Grade::create([
            'student_id' => $s->id, 'subject_id' => $mapel->id, 'classroom_id' => $kelas->id,
            'term_id' => $t->id, 'category' => $cat, 'score' => $score, 'recorded_by' => $admin->id,
        ]);

        $student = fn (string $nama) => Student::create([
            'nama_lengkap' => $nama, 'jenis_kelamin' => 'L',
            'school_unit_id' => $sd->id, 'entry_year_id' => $year->id, 'status' => 'active',
        ]);

        // Budi 60 (di bawah KKM), Cici 85 semester lalu -> 80 kini (turun 5),
        // Eka 90 bersih. Averages: 60, 80, 90 -> rata-rata 76.7.
        $budi = $student('Budi');
        $enroll($budi);
        foreach (['tugas' => 60, 'uts' => 60, 'uas' => 60] as $cat => $score) {
            $grade($budi, $term, $cat, $score);
        }

        $cici = $student('Cici');
        $enroll($cici, 6);
        $prevYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $prevTerm = Term::create(['academic_year_id' => $prevYear->id, 'name' => 'genap', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        foreach (['tugas' => 85, 'uts' => 85, 'uas' => 85] as $cat => $score) {
            $grade($cici, $prevTerm, $cat, $score);
        }
        foreach (['tugas' => 80, 'uts' => 80, 'uas' => 80] as $cat => $score) {
            $grade($cici, $term, $cat, $score);
        }

        $eka = $student('Eka');
        $enroll($eka);
        foreach (['tugas' => 90, 'uts' => 90, 'uas' => 90] as $cat => $score) {
            $grade($eka, $term, $cat, $score);
        }

        $dedi = $student('Dedi');
        $enroll($dedi);
        PointRecord::create([
            'student_id' => $dedi->id, 'term_id' => $term->id, 'type' => 'violation', 'points' => -10,
            'occurred_on' => '2026-09-01', 'description' => 'Terlambat', 'recorded_by' => $admin->id, 'status' => 'recorded',
        ]);

        // Mixed money: partial 650k (paid 200k), overdue unpaid 450k, a
        // cancelled bill that must not exist anywhere in the numbers.
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        Bill::create([
            'bill_number' => 'B/1', 'dedup_key' => 'b1', 'description' => 'SPP Budi',
            'student_id' => $budi->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'paid_amount' => 200000, 'remaining_amount' => 450000,
            'status' => 'partial', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);
        Bill::create([
            'bill_number' => 'B/2', 'dedup_key' => 'b2', 'description' => 'SPP Dedi',
            'student_id' => $dedi->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id,
            'subtotal' => 450000, 'total_amount' => 450000, 'remaining_amount' => 450000,
            'status' => 'unpaid', 'due_date' => now()->subDays(2)->toDateString(), 'issued_at' => now(),
        ]);
        Bill::create([
            'bill_number' => 'B/3', 'dedup_key' => 'b3', 'description' => 'Salah terbit',
            'student_id' => $eka->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id,
            'subtotal' => 100000, 'total_amount' => 100000, 'remaining_amount' => 100000,
            'status' => 'cancelled', 'due_date' => now()->subDays(2)->toDateString(), 'issued_at' => now(),
        ]);

        $res = $this->actingAs($admin)->getJson('/api/admin/dashboard/summary')->assertOk();

        // Grades came through WatchlistService untouched: rounded averages,
        // KKM/decline from the same service the drill-down uses.
        $this->assertSame(3, $res->json('kpi.grades.students_graded'));
        $this->assertEquals(76.7, $res->json('kpi.grades.average'));
        $this->assertSame(1, $res->json('kpi.grades.below_kkm'));
        $this->assertSame(1, $res->json('kpi.grades.declined'));

        // Money summed in SQL: partial + overdue counted, cancelled absent.
        $this->assertSame(2, $res->json('kpi.billing.bill_count'));
        $this->assertEquals(1100000.0, $res->json('kpi.billing.total_billed'));
        $this->assertEquals(200000.0, $res->json('kpi.billing.total_paid'));
        $this->assertEquals(900000.0, $res->json('kpi.billing.total_outstanding'));
        $this->assertSame(1, $res->json('kpi.billing.unpaid_count'));
        $this->assertSame(1, $res->json('kpi.billing.partial_count'));
        $this->assertSame(1, $res->json('kpi.billing.overdue_bills'));
        $this->assertEquals(450000.0, $res->json('kpi.billing.overdue_amount'));

        // Watchlist alerts: Budi below KKM, Cici absenteeism (alpa 6) AND
        // declined, Dedi violation + overdue debtor; Eka nowhere.
        $alerts = collect($res->json('alerts'))->keyBy('id');
        $this->assertSame(1, $alerts['absenteeism']['count']);
        $this->assertSame(1, $alerts['grades']['count']);
        $this->assertSame(1, $alerts['points']['count']);
        $this->assertSame(1, $alerts['overdue']['count']);
        $this->assertSame(2, $alerts['outstanding']['count']);
        $this->assertSame(0, $alerts['unplaced']['count']);

        // The unit row carries the same splits.
        $unit = $res->json('units.0');
        $this->assertSame('SD-13', $unit['unit_code']);
        $this->assertEquals(900000.0, $unit['outstanding']);
        $this->assertSame(1, $unit['overdue_bills']);
        $this->assertSame(1, $unit['students_high_absenteeism']);
        $this->assertSame(1, $unit['students_declined']);
        $this->assertSame(3, $unit['students_needing_attention']);
        $this->assertSame(1, $unit['violation_students']);
    }
}
