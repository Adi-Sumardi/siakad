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
 * DashboardChartController's bill/achievement totals were already scoped
 * via visibleTo() - these pin the part that was not: the `units` list in
 * the response used to always list every school unit, so a unit-scoped
 * admin/guru saw every other unit's code/label alongside their own, just
 * with zeroed-out totals.
 */
class DashboardChartUnitScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_chart_only_lists_a_unit_admins_own_unit(): void
    {
        $year = AcademicYear::create([
            'year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true,
        ]);

        $sdUnit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);
        $smpUnit = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);

        $student = Student::create([
            'nama_lengkap' => 'Anak SD', 'jenis_kelamin' => 'L',
            'school_unit_id' => $sdUnit->id, 'entry_year_id' => $year->id, 'nis' => '000001',
        ]);
        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        Bill::create([
            'bill_number' => 'SPP/2026/08/00001', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus', 'student_id' => $student->id, 'academic_year_id' => $year->id,
            'fee_type_id' => $sppType->id, 'subtotal' => 500000, 'total_amount' => 500000,
            'remaining_amount' => 500000, 'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        $sdAdmin = User::create([
            'name' => 'Admin SD', 'email' => 'sd-chart@example.com', 'phone' => '081111111101',
            'role' => 'admin_unit', 'school_unit_id' => $sdUnit->id, 'is_active' => true,
        ]);

        $body = $this->actingAs($sdAdmin)->getJson('/api/admin/dashboard/billing-chart')->assertOk()->json();

        $unitCodes = collect($body['units'])->pluck('unit_code');
        $this->assertContains('SD-13', $unitCodes);
        $this->assertNotContains('SMP-12', $unitCodes);
    }

    public function test_billing_chart_still_lists_every_unit_for_a_central_admin(): void
    {
        SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);

        $admin = User::create([
            'name' => 'Pusat', 'email' => 'pusat-chart@example.com', 'phone' => '081111111102',
            'role' => 'admin', 'is_active' => true,
        ]);

        $body = $this->actingAs($admin)->getJson('/api/admin/dashboard/billing-chart')->assertOk()->json();

        $unitCodes = collect($body['units'])->pluck('unit_code');
        $this->assertContains('SD-13', $unitCodes);
        $this->assertContains('SMP-12', $unitCodes);
    }
}
