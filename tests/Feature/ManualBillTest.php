<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillReminderSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-off bill lane: an admin (central for any unit, unit admin for their
 * own) issues a bill by hand for the cases the generator never knows about.
 * The bill must be indistinguishable from a generated one - same statuses,
 * same wali portal, same reminder beats, same payment lanes.
 */
class ManualBillTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private FeeType $spp;

    private FeeType $seragam;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12', 'jenjang_group' => 'smp']);

        AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])->activate();

        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly', 'is_active' => true]);
        $this->seragam = FeeType::create(['code' => 'seragam', 'name' => 'Seragam & atribut', 'recurrence' => 'once', 'is_active' => true]);

        $this->student = Student::create([
            'nama_lengkap' => 'Hafidz', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $user = User::create(['name' => 'Iwan Hadi', 'email' => 'iwan@example.com', 'role' => 'orangtua', 'is_active' => true, 'activated_at' => now()]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Iwan Hadi', 'hubungan' => 'ayah']);
        $this->student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);
    }

    private function admin(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_ulid' => $this->student->ulid,
            'fee_type_ulid' => $this->spp->ulid,
            // The default payload bills a recurring type, which since audit
            // r2 2026-10-05 must name its period month (cross-lane dedup).
            'period_month' => 9,
            'description' => 'SPP tertunggak bulan masuk tengah semester',
            'amount' => 650000,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides);
    }

    public function test_a_central_admin_can_issue_a_manual_bill_for_any_unit(): void
    {
        $response = $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('bill.status', 'unpaid')
            ->assertJsonPath('bill.total_amount', 650000)
            ->assertJsonPath('bill.remaining_amount', 650000);

        $bill = Bill::where('bill_number', $response->json('bill.bill_number'))->first();
        $this->assertNotNull($bill);
        $this->assertSame('unpaid', $bill->status);
        $this->assertNotNull($bill->issued_by, 'the issuing admin must be on the bill');
        // A PDF line exists, so the invoice renders like any generated one.
        $this->assertSame(1, BillLine::where('bill_id', $bill->id)->count());
        // Money actions write the audit trail (R6).
        $this->assertDatabaseHas('activity_logs', ['action' => 'bill.manual_created', 'subject_id' => $bill->id]);
    }

    public function test_a_unit_admin_can_only_issue_bills_for_their_own_students(): void
    {
        // The one fee type a unit admin may issue by hand (school decision
        // 2026-09-22) - the scoping subject of this test, not the type.
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once', 'is_active' => true]);

        $this->actingAs($this->admin('admin_unit', $this->smp))
            ->postJson('/api/admin/bills/manual', $this->payload(['fee_type_ulid' => $cambridge->ulid]))
            ->assertNotFound(); // student is SD-13, caller is SMP-12 - 404, not 403 (R3)

        $this->assertDatabaseCount('bills', 0);

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload(['fee_type_ulid' => $cambridge->ulid]))
            ->assertCreated();
    }

    public function test_the_family_sees_the_manual_bill_in_their_portal(): void
    {
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload())
            ->assertCreated();

        $wali = Guardian::first()->user;

        $this->actingAs($wali)
            ->getJson('/api/wali/bills')
            ->assertOk()
            ->assertJsonCount(1, 'bills')
            ->assertJsonPath('bills.0.description', 'SPP tertunggak bulan masuk tengah semester')
            ->assertJsonPath('summary.outstanding', 650000);
    }

    public function test_manual_bills_join_the_reminder_beats_like_any_open_bill(): void
    {
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['due_date' => now()->addDays(7)->toDateString()]))
            ->assertCreated();

        $bill = Bill::first();

        $this->assertSame('h7', app(BillReminderSender::class)->kindFor($bill->fresh()));
    }

    public function test_amount_and_description_are_validated(): void
    {
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['amount' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['description' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_an_inactive_fee_type_is_refused(): void
    {
        $retired = FeeType::create(['code' => 'lama', 'name' => 'Jenis Lama', 'recurrence' => 'once', 'is_active' => false]);

        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['fee_type_ulid' => $retired->ulid]))
            ->assertNotFound();
    }

    public function test_a_recurring_manual_bill_requires_its_period_month(): void
    {
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['period_month' => null]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jenis biaya SPP berulang - pilih bulan periode tagihannya agar tidak dobel dengan generator.');
    }

    public function test_a_recurring_manual_bill_shares_the_generators_period_dedup(): void
    {
        $response = $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['period_month' => 9]));
        $response->assertCreated();

        // The generator's own key shape - the (student, dedup_key) unique
        // then guards BOTH lanes in BOTH orders (audit r2 2026-10-05: a
        // manual month-X bill plus the month-X run used to double-bill).
        $bill = Bill::where('bill_number', $response->json('bill.bill_number'))->first();
        $this->assertSame('spp:2026-2027:09', $bill->dedup_key);
        $this->assertSame(9, $bill->period_month);

        // Manual-vs-manual for the same period...
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['period_month' => 9, 'amount' => 700000]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tagihan SPP periode ini sudah terbit (dibuat manual): '.$bill->bill_number.'.');

        // ...and generator-vs-manual: a generator-style row for October
        // blocks the manual lane for October with the "from generator" note
        // (origin read off billing_run_id - the key shape is shared now).
        $year = \App\Models\AcademicYear::where('is_active', true)->first();
        $run = \App\Models\BillingRun::create([
            'fee_type_id' => $this->spp->id,
            'academic_year_id' => $year->id,
            'period_month' => 10,
            'status' => 'completed',
            'bills_created' => 1,
            'total_amount' => 650000,
        ]);
        Bill::create([
            'bill_number' => 'SPP/2026/10/GEN',
            'dedup_key' => 'spp:2026-2027:10',
            'period_month' => 10,
            'description' => 'SPP Oktober 2026',
            'student_id' => $this->student->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->spp->id,
            'billing_run_id' => $run->id,
            'subtotal' => 650000,
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10)->toDateString(),
            'issued_at' => now(),
        ]);

        $blocked = $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['period_month' => 10]));
        $blocked->assertStatus(422);
        $this->assertStringContainsString('dari generator', (string) $blocked->json('message'));
    }

    public function test_a_past_due_manual_bill_is_born_overdue(): void
    {
        // Mirrors the generator's birth status (audit r2 2026-10-05): a
        // catch-up bill is visible in tunggakan the moment it exists, not
        // after the next 01:00 sweep.
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['due_date' => now()->subDay()->toDateString()]))
            ->assertCreated()
            ->assertJsonPath('bill.status', 'overdue');
    }

    public function test_a_non_active_student_cannot_be_billed(): void
    {
        $this->student->forceFill(['status' => 'graduated'])->save();

        $response = $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload());
        $response->assertStatus(422);
        $this->assertStringContainsString('berstatus graduated', (string) $response->json('message'));
    }
}
