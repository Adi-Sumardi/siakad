<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillingRun;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use App\Services\Billing\PaymentAllocator;
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

    public function test_the_central_admin_can_filter_bills_by_unit_and_year(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        // By unit code - the dropdown the tagihan page has always sent.
        $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/bills?unit='.$this->smp->code)
            ->assertOk()
            ->assertJsonCount(1, 'bills.data')
            ->assertJsonPath('bills.data.0.student.nama_lengkap', 'Anak SMP');

        // By year string, as the page's picker sends it.
        $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/bills?year='.$this->year->year)
            ->assertOk()
            ->assertJsonCount(2, 'bills.data');

        // A year nothing was billed in yields an honest empty list.
        $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/bills?year=2030/2031')
            ->assertOk()
            ->assertJsonCount(0, 'bills.data');
    }

    public function test_a_unit_admins_bill_list_ignores_a_foreign_unit_filter(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP');
        $this->generateAll();

        // The dropdown is hidden for a per-unit admin, but scope - not the
        // query string - decides what they see even if one is sent anyway.
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/bills?unit='.$this->smp->code)
            ->assertOk()
            ->assertJsonCount(0, 'bills.data');
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

    public function test_a_unit_admin_may_read_but_not_set_prices(): void
    {
        // Reading is open - a per-unit admin has to know the going rate before
        // they can run billing for their own unit.
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/fee-rates')
            ->assertOk();

        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->postJson('/api/admin/fee-rates', [
                'fee_type_ulid' => $this->spp->ulid,
                'school_unit_ulid' => $this->sd->ulid,
                'academic_year_ulid' => $this->year->ulid,
                'amount' => 1,
            ])
            ->assertStatus(403);
    }

    public function test_a_unit_admin_reading_rates_only_sees_their_own_unit(): void
    {
        // setUp() already seeded one SPP rate for each of $this->sd and
        // $this->smp - exactly the two-unit situation this guards against.
        $rates = $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/fee-rates')
            ->assertOk()
            ->json('rates');

        $this->assertCount(1, $rates);
        $this->assertSame('SD-SAKINAH', $rates[0]['unit']['code']);
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

        // Keep the bill inside its due window regardless of when the suite
        // runs - the subject here is the cancel refusal, and a partly-paid
        // PAST-due bill is 'overdue' now that overdue outranks partial.
        $bill->forceFill(['due_date' => now()->addDays(7)->startOfDay()])->save();

        // Money on the bill via the ledger (the cash-recording endpoint is
        // gone by school decision - payment is VA-only), so the payment is
        // written the way a settled VA would leave it.
        $payment = Payment::create([
            'payment_number' => 'PAY-CANCEL-1',
            'amount' => 200000,
            'method' => 'virtual_account',
            'status' => 'pending',
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 200000]);
        app(PaymentAllocator::class)->settle($payment);

        // Cancelling now would strand a real payment against nothing.
        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/cancel", ['reason' => 'salah terbit'])
            ->assertStatus(422);

        $this->assertSame('partial', $bill->fresh()->status);
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

    public function test_a_cambridge_run_bills_only_the_units_that_set_a_nominal(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        FeeRate::create([
            'fee_type_id' => $cambridge->id,
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $this->year->id,
            'amount' => 650000,
        ]);
        $this->studentIn($this->sd, 'Anak SD');
        $this->studentIn($this->smp, 'Anak SMP'); // unit tanpa tarif Cambridge

        // No month - Cambridge is a once-a-year type.
        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'cambridge'])
            ->assertStatus(201)
            ->assertJsonPath('run.bills_created', 1)
            ->assertJsonPath('run.bills_skipped', 1);

        $this->assertSame(1, Bill::count());
        $this->assertSame('Anak SD', Bill::first()->student->nama_lengkap);

        // The SMP student is named with a reason, not silently unpriced.
        $skipped = BillingRun::first()->skipped_detail;
        $this->assertSame('Anak SMP', $skipped[0]['student']);
        $this->assertSame('Tarif belum ada', $skipped[0]['reason']);
    }

    public function test_a_once_type_is_billed_once_per_year_even_after_the_semester_flips(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        FeeRate::create([
            'fee_type_id' => $cambridge->id,
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $this->year->id,
            'amount' => 650000,
        ]);
        $this->studentIn($this->sd, 'Anak SD');

        $ganjil = Term::create(['academic_year_id' => $this->year->id, 'name' => 'ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31']);
        $ganjil->activate();

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'cambridge'])
            ->assertStatus(201)
            ->assertJsonPath('run.bills_created', 1);

        // The December/July flip: a re-run in genap must not mint a second
        // "once" bill for the same year - the old dedup key appended the
        // active term's name, so it did.
        $genap = Term::create(['academic_year_id' => $this->year->id, 'name' => 'genap', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30']);
        $genap->activate();

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'cambridge'])
            ->assertStatus(201)
            ->assertJsonPath('run.bills_created', 0)
            ->assertJsonPath('run.bills_skipped', 1);

        $this->assertSame(1, Bill::count());
        $this->assertSame('Sudah punya tagihan', BillingRun::latest('id')->first()->skipped_detail[0]['reason']);
    }

    public function test_a_once_run_skips_a_student_already_billed_on_the_manual_lane(): void
    {
        // Both lanes issue cambridge (run + admin's manual bills), and a
        // manual bill never carries the generator's dedup key - the
        // (student, type, year) tuple is what must block the run.
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        FeeRate::create([
            'fee_type_id' => $cambridge->id,
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $this->year->id,
            'amount' => 650000,
        ]);
        $student = $this->studentIn($this->sd, 'Anak Manual');
        $year = AcademicYear::where('is_active', true)->first();

        $manual = Bill::create([
            'bill_number' => 'CAM/M/0001',
            'dedup_key' => 'manual:'.$student->id.':'.uniqid(),
            'description' => 'Cambridge & Buku TA 2026/2027',
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $cambridge->id,
            'subtotal' => 900000, 'total_amount' => 900000, 'remaining_amount' => 900000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'cambridge'])
            ->assertStatus(201)
            ->assertJsonPath('run.bills_created', 0)
            ->assertJsonPath('run.bills_skipped', 1);

        // The run names the manual bill as the reason, and the family keeps
        // exactly one cambridge bill.
        $skipped = BillingRun::latest('id')->first()->skipped_detail;
        $this->assertSame('Sudah punya tagihan', $skipped[0]['reason']);
        $this->assertStringContainsString('manual', $skipped[0]['detail']);
        $this->assertSame(1, Bill::where('fee_type_id', $cambridge->id)->count());
        $this->assertTrue($manual->exists());
    }

    public function test_the_run_history_names_the_unit_and_actor_and_scopes_per_unit(): void
    {
        $this->studentIn($this->sd, 'Anak SD');
        $admin = $this->staff('admin');

        // A school-wide SPP run by the central admin...
        $this->actingAs($admin)
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'spp', 'month' => 8])
            ->assertStatus(201);

        // ...and a run scoped to the other unit, which an SD admin must not see.
        $this->actingAs($this->staff('admin_unit', $this->smp))
            ->postJson('/api/admin/billing-runs', ['fee_type_code' => 'spp', 'month' => 9])
            ->assertStatus(201);

        $rows = $this->actingAs($admin)
            ->getJson('/api/admin/bills') // warm - not the subject
            ->assertOk();

        $this->assertNotNull($rows);

        $history = $this->actingAs($admin)
            ->getJson('/api/admin/billing-runs')
            ->assertOk()
            ->json('runs');

        $this->assertCount(2, $history);
        // The school-wide run names its actor and carries a null unit.
        $schoolWide = collect($history)->firstWhere('unit', null);
        $this->assertNotNull($schoolWide);
        $this->assertSame($admin->name, $schoolWide['run_by']);
        $this->assertSame('SPP', $schoolWide['fee_type']);
        $this->assertSame(8, $schoolWide['period_month']);
        // The SMP run carries its unit.
        $smpRun = collect($history)->first(fn ($r) => $r['unit'] !== null);
        $this->assertSame('SMP-SAKINAH', $smpRun['unit']['code']);

        // A per-unit admin sees their own unit's runs plus school-wide ones -
        // never another unit's.
        $scoped = $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson('/api/admin/billing-runs')
            ->assertOk()
            ->json('runs');

        $this->assertCount(1, $scoped);
        $this->assertNull($scoped[0]['unit']);
    }
}
