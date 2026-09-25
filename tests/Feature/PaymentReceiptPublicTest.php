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
use App\Services\Billing\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public receipt lane (feature batch Poin 11A/B/C): the token is born
 * atomically with the settle claim, the public endpoint answers with a
 * deliberately minimal payload (no NIS, no ULID), wrong tokens are an
 * undifferentiated 404, and the admin payment history is filterable the
 * way a reconciliation actually needs.
 */
class PaymentReceiptPublicTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private SchoolUnit $unit;

    private FeeType $spp;

    private User $admin;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $student = Student::create([
            'nama_lengkap' => 'Anak Struk Publik',
            'jenis_kelamin' => 'L',
            'nis' => '99123',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);
        $guardian = Guardian::create(['nama' => 'Wali Struk', 'hubungan' => 'ayah']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $this->bill = Bill::create([
            'bill_number' => 'SPP/2026/09/00099',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:2026:09:'.$student->id,
            'description' => 'SPP September 2026',
            'subtotal' => 650000,
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'issued_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function settlePayment(): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'PAY-RECEIPT-1',
            'payer_guardian_id' => $this->bill->student->guardians->first()->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000RE', 'bank_name' => 'Bank Muamalat'],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$this->bill->id => 650000]);
        app(PaymentAllocator::class)->settle($payment, 'EXT-RECEIPT-1');

        return $payment->fresh();
    }

    public function test_the_token_is_born_with_the_settle_and_is_idempotent(): void
    {
        $payment = $this->settlePayment();

        $this->assertSame(32, strlen((string) $payment->receipt_public_token));
        $this->assertSame('Bank Muamalat', $payment->channel, 'backfill kanal dari gateway_response');

        // A second settle attempt (double-delivered callback) changes
        // nothing - the claim is the same conditional UPDATE.
        app(PaymentAllocator::class)->settle($payment, 'EXT-RECEIPT-2');
        $this->assertSame($payment->receipt_public_token, $payment->fresh()->receipt_public_token);
    }

    public function test_the_public_endpoint_answers_minimally_and_guesses_fail(): void
    {
        $payment = $this->settlePayment();
        $token = $payment->receipt_public_token;

        $body = $this->getJson("/api/receipt/{$token}")->assertOk()->json('receipt');
        $this->assertEquals(650000.0, $body['amount']);
        $this->assertSame('LUNAS', $body['status']);
        $this->assertSame('Anak Struk Publik', $body['students'][0]['nama_lengkap']);
        $this->assertArrayNotHasKey('ulid', $body);
        $this->assertArrayNotHasKey('nis', $body['students'][0]);

        // Guessing and tampering both land on an undifferentiated 404 -
        // there is no enumeration surface.
        $this->getJson('/receipt/'.str_repeat('A', 32))->assertNotFound();
        $this->getJson('/receipt/short-token')->assertNotFound();

        // An unsettled payment has no token at all - nothing to find.
        $pending = Payment::create([
            'payment_number' => 'PAY-RECEIPT-2',
            'payer_guardian_id' => $this->bill->student->guardians->first()->id,
            'amount' => 1,
            'method' => 'virtual_account',
            'status' => 'processing',
        ]);
        $this->assertNull($pending->receipt_public_token);
    }

    public function test_the_admin_history_filters_and_share_link_work(): void
    {
        $payment = $this->settlePayment();

        // q by bill number, by student name, by NIS.
        $this->actingAs($this->admin)->getJson('/api/admin/payments?q=00099')->assertOk()->assertJsonCount(1, 'payments.data');
        $this->actingAs($this->admin)->getJson('/api/admin/payments?q=Anak Struk')->assertOk()->assertJsonCount(1, 'payments.data');
        $this->actingAs($this->admin)->getJson('/api/admin/payments?q=99123')->assertOk()->assertJsonCount(1, 'payments.data');
        $this->actingAs($this->admin)->getJson('/api/admin/payments?q=TIDAKADA')->assertOk()->assertJsonCount(0, 'payments.data');

        // Status + channel + unit + fee type.
        $this->actingAs($this->admin)->getJson('/api/admin/payments?status=completed&channel=Bank+Muamalat&unit=SD-13&fee_type=spp')
            ->assertOk()->assertJsonCount(1, 'payments.data');

        // The receipt PDF comes back whole.
        $this->actingAs($this->admin)->get("/api/admin/payments/{$payment->ulid}/receipt")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // The share link is idempotent and returns the same path.
        $first = $this->actingAs($this->admin)->postJson("/api/admin/payments/{$payment->ulid}/share-link")->assertOk()->json('url');
        $second = $this->actingAs($this->admin)->postJson("/api/admin/payments/{$payment->ulid}/share-link")->assertOk()->json('url');
        $this->assertSame($first, $second);
        $this->assertSame("/receipt/{$payment->receipt_public_token}", $first);
    }

    public function test_a_unit_admin_sees_only_their_units_payments(): void
    {
        $this->settlePayment();

        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);
        $adminUnit = User::create([
            'name' => 'Admin Unit SMP',
            'email' => 'ausmp'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $smp->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($adminUnit)->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'payments.data');
        $this->actingAs($adminUnit)->getJson("/api/admin/payments?q=00099")->assertOk()->assertJsonCount(0, 'payments.data');
    }
}
