<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a fee type or a rate ("Master Jenis Biaya" / "Daftar Tarif
 * Berlaku" on /admin/tarif) is central-admin only, same tier as the rest of
 * this controller's writes - reading stays open to a unit's own admin_unit.
 */
class FeeSettingAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $unitAdmin;
    private SchoolUnit $unit;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Administrator', 'email' => 'admin@example.com', 'phone' => '081111111111',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->unitAdmin = User::create([
            'name' => 'Admin Unit', 'email' => 'admin-unit@example.com', 'phone' => '081111111112',
            'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true,
        ]);
    }

    public function test_administrator_can_delete_a_fee_type_never_billed(): void
    {
        $type = FeeType::create(['code' => 'buku', 'name' => 'Buku', 'recurrence' => 'once']);

        $this->actingAs($this->admin)->deleteJson("/api/admin/fee-types/{$type->ulid}")->assertOk();
        $this->assertDatabaseMissing('fee_types', ['id' => $type->id]);
    }

    public function test_it_refuses_to_delete_a_fee_type_already_billed(): void
    {
        $type = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $student = Student::create(['nama_lengkap' => 'Anak Uji', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'entry_year_id' => $this->year->id]);
        Bill::create([
            'bill_number' => 'SPP/2026/08/00001', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus', 'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'fee_type_id' => $type->id, 'subtotal' => 500000, 'total_amount' => 500000, 'remaining_amount' => 500000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/fee-types/{$type->ulid}")->assertStatus(422);
        $this->assertDatabaseHas('fee_types', ['id' => $type->id]);
    }

    public function test_administrator_can_delete_a_rate(): void
    {
        $type = FeeType::create(['code' => 'jamiyyah', 'name' => 'Uang Jamiyyah', 'recurrence' => 'per_term']);
        $rate = FeeRate::create([
            'fee_type_id' => $type->id, 'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 100000, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/fee-rates/{$rate->ulid}")->assertOk();
        $this->assertDatabaseMissing('fee_rates', ['id' => $rate->id]);
    }

    public function test_deleting_a_rate_leaves_a_bill_already_issued_under_it_untouched(): void
    {
        $type = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $rate = FeeRate::create([
            'fee_type_id' => $type->id, 'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 500000, 'is_active' => true,
        ]);
        $student = Student::create(['nama_lengkap' => 'Anak Uji', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'entry_year_id' => $this->year->id]);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00001', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus', 'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'fee_type_id' => $type->id, 'fee_rate_id' => $rate->id, 'subtotal' => 500000, 'total_amount' => 500000,
            'remaining_amount' => 500000, 'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/fee-rates/{$rate->ulid}")->assertOk();

        // The bill survives with its own amount intact - only the FK link clears.
        $this->assertDatabaseHas('bills', ['id' => $bill->id, 'total_amount' => 500000]);
        $this->assertNull($bill->fresh()->fee_rate_id);
    }

    public function test_a_unit_admin_cannot_delete_a_fee_type_or_rate(): void
    {
        $type = FeeType::create(['code' => 'buku', 'name' => 'Buku', 'recurrence' => 'once']);
        $rate = FeeRate::create([
            'fee_type_id' => $type->id, 'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 100000, 'is_active' => true,
        ]);

        $this->actingAs($this->unitAdmin)->deleteJson("/api/admin/fee-types/{$type->ulid}")->assertForbidden();
        $this->actingAs($this->unitAdmin)->deleteJson("/api/admin/fee-rates/{$rate->ulid}")->assertForbidden();
    }
}
