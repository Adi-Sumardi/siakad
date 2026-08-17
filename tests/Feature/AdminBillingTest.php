<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may do what with money.
 *
 * A per-unit admin bills their own unit and chases their own arrears; they do
 * not set prices, and they cannot reach another unit's families. Both halves
 * matter: the role check decides which endpoints open, visibleTo() decides
 * which rows come back, and a test that only proves the first would miss an
 * admin reading another unit's list through an endpoint they are allowed to
 * call.
 */
class AdminBillingTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        foreach ([$this->sd, $this->smp] as $unit) {
            FeeRate::create([
                'fee_type_id' => $this->spp->id,
                'school_unit_id' => $unit->id,
                'academic_year_id' => $this->year->id,
                'amount' => 650000,
                'due_day' => 10,
            ]);
        }
    }

    private function studentIn(SchoolUnit $unit, string $name): Student
    {
        return Student::create([
            'nama_lengkap' => $name,
            'jenis_kelamin' => 'P',
            'school_unit_id' => $unit->id,
            'status' => 'active',
        ]);
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.uniqid().'@yapinet.id',
            'role' => $role,
            'school_unit_id' => $unit?->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function generateAll(): void
    {
        app(BillGenerator::class)->run($this->spp, $this->year, null, 8);
    }

    public function test_a_unit_admin_sees_only_their_own_units_bills(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/bills')
            ->assertOk()
            ->assertJsonPath('bills.data.0.student.nama_lengkap', 'Anak SD')
            ->assertJsonCount(1, 'bills.data');
    }

    public function test_a_central_admin_sees_every_unit(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/bills')
            ->assertOk()
            ->assertJsonCount(2, 'bills.data');
    }

    public function test_a_unit_admin_cannot_touch_another_units_bill(): void
    {
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        $bill = Bill::first();

        // 404, not 403: confirming the bill exists would already tell a unit's
        // admin something about a family that is not theirs.
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->postJson("/api/admin/bills/{$bill->ulid}/waive", ['reason' => 'coba-coba'])
            ->assertStatus(404);

        $this->assertSame('unpaid', $bill->fresh()->status);
    }

    public function test_a_unit_admin_may_not_set_prices(): void
    {
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/fee-rates')
            ->assertStatus(403);

        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->postJson('/api/admin/fee-rates', [
                'fee_type_ulid' => $this->spp->ulid,
                'school_unit_ulid' => $this->sd->ulid,
                'academic_year_ulid' => $this->year->ulid,
                'amount' => 1,
            ])
            ->assertStatus(403);
    }

    public function test_a_guardian_cannot_reach_the_admin_area_at_all(): void
    {
        $parent = User::create([
            'name' => 'Budi', 'email' => 'budi@example.com', 'role' => 'orangtua',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($parent)->getJson('/api/admin/bills')->assertStatus(403);
        $this->actingAs($parent)->getJson('/api/admin/reports/receivables')->assertStatus(403);
    }

    public function test_a_unit_admins_billing_run_is_forced_to_their_own_unit(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');

        // Asks for the other unit; must get their own regardless.
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->postJson('/api/admin/billing-runs', [
                'fee_type_code' => 'spp',
                'month' => 8,
                'unit_code' => 'SMP-SAKINAH',
            ])
            ->assertStatus(201)
            ->assertJsonPath('run.bills_created', 1);

        $this->assertSame(1, Bill::count());
        $this->assertSame('Anak SD', Bill::first()->student->nama_lengkap);
    }

    public function test_preview_shows_the_damage_before_anything_is_issued(): void
    {
        $this->studentIn($this->sd, 'Punya tarif');

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/billing-runs/preview', ['fee_type_code' => 'spp', 'month' => 8])
            ->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('total_amount', 650000);

        $this->assertSame(0, Bill::count());
    }

    public function test_waiving_a_bill_requires_a_reason_and_is_logged(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->generateAll();
        $bill = Bill::first();
        $admin = $this->staff('admin');

        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/waive", [])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/waive", ['reason' => 'Keluarga terdampak musibah'])
            ->assertOk();

        $bill->refresh();
        $this->assertSame('waived', $bill->status);
        $this->assertEquals(0.0, (float) $bill->remaining_amount);

        // Money written off must always be traceable to who did it and why.
        $this->assertDatabaseHas('activity_logs', ['action' => 'bill.waived', 'user_id' => $admin->id]);
    }

    public function test_a_bill_that_took_money_cannot_be_cancelled(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->generateAll();
        $bill = Bill::first();
        $admin = $this->staff('admin');

        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/payments", ['amount' => 200000, 'method' => 'cash'])
            ->assertStatus(201);

        // Cancelling now would strand a real payment against nothing.
        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/cancel", ['reason' => 'salah terbit'])
            ->assertStatus(422);

        $this->assertSame('partial', $bill->fresh()->status);
    }

    public function test_recording_cash_settles_the_bill_through_the_ledger(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->generateAll();
        $bill = Bill::first();

        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->postJson("/api/admin/bills/{$bill->ulid}/payments", [
                'amount' => 650000,
                'method' => 'cash',
                'notes' => 'Dibayar di TU',
            ])
            ->assertStatus(201);

        $bill->refresh();
        $this->assertSame('paid', $bill->status);
        // Recorded as a payment with an allocation, not by editing the bill -
        // so it appears in the collections report like any other money.
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_the_receivables_report_is_scoped_and_grouped_by_class(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/reports/receivables')
            ->assertOk()
            ->assertJsonPath('summary.outstanding', 650000)
            ->assertJsonPath('summary.families', 1);
    }

    public function test_a_fee_types_code_cannot_be_renamed(): void
    {
        $this->actingAs($this->staff('admin'))
            ->patchJson("/api/admin/fee-types/{$this->spp->ulid}", [
                'code' => 'spp-baru',
                'name' => 'SPP Baru',
            ])
            ->assertOk();

        // The code is what every dedup_key already written is built on; letting
        // it change would orphan the bills issued under the old one.
        $this->assertSame('spp', $this->spp->fresh()->code);
        $this->assertSame('SPP Baru', $this->spp->fresh()->name);
    }

    public function test_a_duplicate_rate_is_refused_with_an_explanation(): void
    {
        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/fee-rates', [
                'fee_type_ulid' => $this->spp->ulid,
                'school_unit_ulid' => $this->sd->ulid,
                'academic_year_ulid' => $this->year->ulid,
                'amount' => 700000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tarif untuk kombinasi jenis biaya, unit, tingkat, dan tahun ajaran ini sudah ada.');
    }
}
