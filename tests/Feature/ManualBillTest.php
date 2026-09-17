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
        $this->actingAs($this->admin('admin_unit', $this->smp))
            ->postJson('/api/admin/bills/manual', $this->payload())
            ->assertNotFound(); // student is SD-13, caller is SMP-12 - 404, not 403 (R3)

        $this->assertDatabaseCount('bills', 0);

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload())
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
}
