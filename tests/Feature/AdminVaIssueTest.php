<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingApiClient;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * "Buat VA" - an admin mints the Virtual Account for one bill on the
 * family's behalf. It must walk the same lane a wali checkout walks (one VA
 * group, prefix assertion, supersede guards, allocator) with the student's
 * billing contact as payer, and the number must reach the family on
 * WhatsApp. A per-unit admin's money moves are cambridge-shaped only - the
 * same line the fee rates and manual bills draw.
 */
class AdminVaIssueTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private FeeType $cambridge;

    private FeeType $spp;

    private Student $student;

    private Guardian $billingContact;

    private User $waliUser;

    /** @var array<int, array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12', 'jenjang_group' => 'smp']);
        AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])->activate();

        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $this->cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        $this->student = Student::create([
            'nama_lengkap' => 'Hafidz Cambridge', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $this->waliUser = User::create(['name' => 'Wali Hafidz', 'role' => 'orangtua', 'phone' => '081299900001', 'is_active' => true, 'activated_at' => now()]);
        $this->billingContact = Guardian::create([
            'user_id' => $this->waliUser->id,
            'nama' => 'Wali Hafidz',
            'hubungan' => 'ayah',
            'no_hp' => '081299900001',
        ]);
        $this->student->guardians()->attach($this->billingContact->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $this->app->bind(WhatsAppGateway::class, fn () => new class($this->sentWhatsApp) implements WhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendMessage(string $phone, string $message): NotificationResult
            {
                $this->sent[] = compact('phone', 'message');

                return NotificationResult::ok();
            }
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function admin(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function cambridgeBill(Student $student, float $amount = 900000): Bill
    {
        $year = AcademicYear::where('is_active', true)->first();

        return Bill::create([
            'bill_number' => 'CAM/'.uniqid(),
            'dedup_key' => 'manual:'.$student->id.':'.uniqid(),
            'description' => 'Program Cambridge & Buku TA 2026/2027',
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $this->cambridge->id,
            'subtotal' => $amount, 'total_amount' => $amount, 'remaining_amount' => $amount,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);
    }

    private function mockBillingClient(): Mockery\MockInterface
    {
        $mock = Mockery::mock(BillingApiClient::class);
        // andReturnUsing, not andReturn: e-SPP hands out a fresh billing uuid
        // per call, and a shared one trips payments.external_transaction_id's
        // unique index on the second issue.
        $mock->shouldReceive('createBilling')->andReturnUsing(fn () => ['uuid' => 'bill-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mock);

        return $mock;
    }

    public function test_an_admin_issues_the_va_and_the_family_hears_about_it(): void
    {
        $this->mockBillingClient();
        $bill = $this->cambridgeBill($this->student);
        $expectedVa = '802009'.'2627'.str_pad((string) $this->student->id, 6, '0', STR_PAD_LEFT);

        $response = $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/bills/{$bill->ulid}/va", ['bank' => 'muamalat']);

        $response->assertCreated()
            ->assertJsonPath('payment.virtual_account.va_number', $expectedVa)
            ->assertJsonPath('whatsapp.sent', true);

        $payment = Payment::where('ulid', $response->json('payment.ulid'))->first();

        // The payer is the student's billing contact, so the family's own
        // feed carries the payment - the admin never becomes the payer.
        $this->assertEquals($this->billingContact->id, $payment->payer_guardian_id);

        $this->actingAs($this->waliUser)
            ->getJson('/api/wali/payments')
            ->assertOk()
            ->assertJsonCount(1, 'payments.data');

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'whatsapp',
            'template' => 'va_issued',
            'notifiable_type' => Payment::class,
            'notifiable_id' => $payment->id,
            'status' => 'sent',
        ]);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame('081299900001', $this->sentWhatsApp[0]['phone']);
        $this->assertStringContainsString($expectedVa, $this->sentWhatsApp[0]['message']);
        $this->assertStringContainsString('Bank Muamalat', $this->sentWhatsApp[0]['message']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.va_issued', 'subject_id' => $payment->id]);
    }

    public function test_the_billing_contact_outranks_the_primary_guardian(): void
    {
        $this->mockBillingClient();

        $primaryUser = User::create(['name' => 'Ibu', 'role' => 'orangtua', 'phone' => '081299900002', 'is_active' => true, 'activated_at' => now()]);
        $primary = Guardian::create(['user_id' => $primaryUser->id, 'nama' => 'Ibu', 'hubungan' => 'ibu', 'no_hp' => '081299900002']);
        $this->student->guardians()->attach($primary->id, ['relationship' => 'ibu', 'is_primary' => true, 'is_billing_contact' => false]);

        // Detach the original contact so only the priority order decides:
        // the ibu is primary, nobody is flagged - she is the pick.
        $this->student->guardians()->detach($this->billingContact->id);

        $bill = $this->cambridgeBill($this->student);

        $response = $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/bills/{$bill->ulid}/va");

        $response->assertCreated();
        $this->assertEquals($primary->id, Payment::first()->payer_guardian_id);
    }

    public function test_a_student_without_any_guardian_is_refused_before_a_payment_exists(): void
    {
        $this->mockBillingClient();
        $this->student->guardians()->detach();

        $orphan = Student::create([
            'nama_lengkap' => 'Tanpa Wali', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);
        $bill = $this->cambridgeBill($orphan);

        $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/bills/{$bill->ulid}/va")
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_unit_admin_mints_vas_for_their_own_cambridge_bills_only(): void
    {
        $this->mockBillingClient();

        $cambridgeBill = $this->cambridgeBill($this->student);

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson("/api/admin/bills/{$cambridgeBill->ulid}/va")
            ->assertCreated();

        // Non-cambridge bill in their own unit: refused.
        $sppBill = Bill::create([
            'bill_number' => 'SPP/1', 'dedup_key' => 'spp:2026-2027:07:'.$this->student->id,
            'description' => 'SPP Juli', 'student_id' => $this->student->id,
            'academic_year_id' => AcademicYear::where('is_active', true)->first()->id,
            'fee_type_id' => $this->spp->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson("/api/admin/bills/{$sppBill->ulid}/va")
            ->assertStatus(403);

        // Cambridge bill of another unit: not theirs to see - 404, not 403.
        $smpStudent = Student::create([
            'nama_lengkap' => 'Anak SMP', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp->id, 'status' => 'active',
        ]);
        $foreignBill = $this->cambridgeBill($smpStudent);

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson("/api/admin/bills/{$foreignBill->ulid}/va")
            ->assertNotFound();
    }

    public function test_a_settled_bill_cannot_get_a_new_va(): void
    {
        $this->mockBillingClient();
        $bill = $this->cambridgeBill($this->student);
        $bill->forceFill(['status' => 'paid', 'paid_amount' => 900000, 'remaining_amount' => 0])->save();

        $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/bills/{$bill->ulid}/va")
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_issuing_again_supersedes_the_earlier_va_not_doubles_it(): void
    {
        $bill = $this->cambridgeBill($this->student);

        $this->mockBillingClient();

        // Same bill twice: supersedePendingPaymentsFor() fails the first VA
        // outright (no bank call - the poller is the safety net for a payment
        // that lands on a closed VA), so the second issue mints a fresh one.
        $this->actingAs($this->admin('admin'))->postJson("/api/admin/bills/{$bill->ulid}/va")->assertCreated();
        $this->actingAs($this->admin('admin'))->postJson("/api/admin/bills/{$bill->ulid}/va")->assertCreated();

        $this->assertSame('failed', Payment::oldest('id')->first()->status);
        $this->assertSame('processing', Payment::latest('id')->first()->status);
    }

    public function test_the_bsi_channel_mints_a_bsi_prefixed_va(): void
    {
        $this->mockBillingClient();
        $bill = $this->cambridgeBill($this->student);

        $response = $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/bills/{$bill->ulid}/va", ['bank' => 'bsi']);

        $response->assertCreated()
            ->assertJsonPath(
                'payment.virtual_account.va_number',
                '789509'.'2627'.str_pad((string) $this->student->id, 6, '0', STR_PAD_LEFT),
            )
            ->assertJsonPath('payment.virtual_account.bank_code', '451');
    }
}
