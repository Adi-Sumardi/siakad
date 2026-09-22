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
 *
 * The one write exception is the Cambridge rate (school decision 2026-09-22):
 * a unit admin may store/update their OWN unit's Cambridge nominal - anything
 * else, or any other unit, is refused by the controller itself.
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

    private function cambridgePayload(string $typeUlid, string $unitUlid, float $amount = 750000): array
    {
        return [
            'fee_type_ulid' => $typeUlid,
            'school_unit_ulid' => $unitUlid,
            'academic_year_ulid' => $this->year->ulid,
            'tingkat' => null,
            'amount' => $amount,
        ];
    }

    public function test_a_unit_admin_can_set_their_own_units_cambridge_rate(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        $this->actingAs($this->unitAdmin)
            ->postJson('/api/admin/fee-rates', $this->cambridgePayload($cambridge->ulid, $this->unit->ulid, 650000))
            ->assertCreated();

        $this->assertDatabaseHas('fee_rates', [
            'fee_type_id' => $cambridge->id,
            'school_unit_id' => $this->unit->id,
            'amount' => 650000,
        ]);
    }

    public function test_a_unit_admins_cambridge_rate_lands_on_their_own_unit_even_when_naming_another(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        $smp55 = SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMP Islam Al Azhar 55', 'jenjang_group' => 'smp', 'is_active' => true]);

        // The request names SMP-55; the account belongs to the SD unit. The
        // unit is forced from the account, never trusted from the request -
        // the same line BillingRunController draws.
        $this->actingAs($this->unitAdmin)
            ->postJson('/api/admin/fee-rates', $this->cambridgePayload($cambridge->ulid, $smp55->ulid))
            ->assertCreated();

        $this->assertDatabaseHas('fee_rates', ['fee_type_id' => $cambridge->id, 'school_unit_id' => $this->unit->id]);
        $this->assertDatabaseMissing('fee_rates', ['fee_type_id' => $cambridge->id, 'school_unit_id' => $smp55->id]);
    }

    public function test_a_unit_admin_cannot_set_a_non_cambridge_rate(): void
    {
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $this->actingAs($this->unitAdmin)
            ->postJson('/api/admin/fee-rates', $this->cambridgePayload($spp->ulid, $this->unit->ulid))
            ->assertForbidden();

        $this->assertDatabaseCount('fee_rates', 0);
    }

    public function test_a_unit_admin_of_a_non_cambridge_unit_cannot_set_the_rate_either(): void
    {
        // TK is not in the Cambridge program - a rate there could never mint
        // a VA, so the controller refuses before the row exists.
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        $tkUnit = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TK Islam Al Azhar 13', 'jenjang_group' => 'tk', 'is_active' => true]);
        $tkAdmin = User::create([
            'name' => 'Admin TK', 'email' => 'admin-tk@example.com', 'phone' => '081111111113',
            'role' => 'admin_unit', 'school_unit_id' => $tkUnit->id, 'is_active' => true,
        ]);

        $this->actingAs($tkAdmin)
            ->postJson('/api/admin/fee-rates', $this->cambridgePayload($cambridge->ulid, $tkUnit->ulid))
            ->assertForbidden();

        $this->assertDatabaseCount('fee_rates', 0);
    }

    public function test_a_unit_admin_can_update_their_own_cambridge_rate_but_nothing_else(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $ownCambridge = FeeRate::create([
            'fee_type_id' => $cambridge->id, 'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 650000, 'is_active' => true,
        ]);
        $ownSpp = FeeRate::create([
            'fee_type_id' => $spp->id, 'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 500000, 'is_active' => true,
        ]);

        $smp12 = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);
        $foreignCambridge = FeeRate::create([
            'fee_type_id' => $cambridge->id, 'school_unit_id' => $smp12->id, 'academic_year_id' => $this->year->id,
            'tingkat' => null, 'amount' => 900000, 'is_active' => true,
        ]);

        // Own Cambridge: allowed.
        $this->actingAs($this->unitAdmin)
            ->patchJson("/api/admin/fee-rates/{$ownCambridge->ulid}", ['amount' => 700000])
            ->assertOk();
        $this->assertDatabaseHas('fee_rates', ['id' => $ownCambridge->id, 'amount' => 700000]);

        // Own unit, but not Cambridge: refused.
        $this->actingAs($this->unitAdmin)
            ->patchJson("/api/admin/fee-rates/{$ownSpp->ulid}", ['amount' => 550000])
            ->assertForbidden();
        $this->assertDatabaseHas('fee_rates', ['id' => $ownSpp->id, 'amount' => 500000]);

        // Cambridge, but another unit's: refused.
        $this->actingAs($this->unitAdmin)
            ->patchJson("/api/admin/fee-rates/{$foreignCambridge->ulid}", ['amount' => 950000])
            ->assertForbidden();
        $this->assertDatabaseHas('fee_rates', ['id' => $foreignCambridge->id, 'amount' => 900000]);
    }

    public function test_a_central_admin_still_prices_cambridge_for_any_participating_unit(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        $smp12 = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/fee-rates', $this->cambridgePayload($cambridge->ulid, $smp12->ulid, 900000))
            ->assertCreated();

        $this->assertDatabaseHas('fee_rates', ['fee_type_id' => $cambridge->id, 'school_unit_id' => $smp12->id, 'amount' => 900000]);
    }
}
