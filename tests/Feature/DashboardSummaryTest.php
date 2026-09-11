<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
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
}
