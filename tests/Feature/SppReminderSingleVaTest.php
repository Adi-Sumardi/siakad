<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Services\Billing\BillingApiClient;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The SPP reminder carries ONE Virtual Account (single-VA since 2026-10-05).
 * It used to register both banks' VAs for one bill simultaneously - the only
 * lane that broke the app's normal "one live VA per bill" rule - and a family
 * that paid both within seconds produced two completed payments with the
 * excess buried by max(0, ...). The reminder now reuses whatever VA is
 * already live for the bill (whichever bank a checkout chose) or registers
 * the configured reminder bank (Muamalat by default); BSI stays selectable in
 * the app's checkout. The backstop for any cross-lane pair that still slips
 * through is the overpayment guard in PaymentAllocator::settle(), covered by
 * OverpaymentSettleTest.
 */
class SppReminderSingleVaTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    private Student $student;

    private Guardian $guardian;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $this->student = Student::create([
            'nama_lengkap' => 'Naila Zahra Ramadhani',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '700',
        ]);
        $this->guardian = Guardian::create(['nama' => 'Ibu Naila', 'hubungan' => 'ibu', 'no_hp' => '081200000700']);
        $this->student->guardians()->attach($this->guardian->id, ['relationship' => 'ibu', 'is_primary' => true, 'is_billing_contact' => true]);

        $this->bill = Bill::create([
            'bill_number' => 'SPP/2026/09/00700',
            'dedup_key' => 'spp:2026:09:'.$this->student->id,
            'description' => 'SPP Bulan September 2026',
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'subtotal' => 700000,
            'total_amount' => 700000,
            'remaining_amount' => 700000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);
    }

    public function test_ensure_reminder_va_registers_a_single_muamalat_va(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->once()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $result = app(BillingApiGateway::class)->ensureReminderVa($this->bill, $this->guardian);

        $studentCode = str_pad((string) $this->student->id, 6, '0', STR_PAD_LEFT);
        $this->assertSame('802001'.'2627'.$studentCode, $result['va_number']);
        $this->assertSame('muamalat', $result['bank']);
        $this->assertSame('Bank Muamalat', $result['bank_name']);

        // Exactly one live Payment row for the bill - never a second bank's.
        $this->assertSame(1, Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->count());
    }

    public function test_ensure_reminder_va_is_idempotent_across_repeated_reminder_beats(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        // Exactly once total across BOTH calls below, not twice - the second
        // call (a later reminder beat, e.g. h1 after h7) must reuse the VA
        // the first call already registered rather than minting a new
        // Payment row (and a new e-SPP billing record) every beat.
        $mockClient->shouldReceive('createBilling')->once()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $gateway = app(BillingApiGateway::class);
        $first = $gateway->ensureReminderVa($this->bill, $this->guardian);
        $second = $gateway->ensureReminderVa($this->bill->fresh(), $this->guardian);

        $this->assertSame($first['va_number'], $second['va_number']);
        $this->assertSame(1, Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->count());
    }

    public function test_ensure_reminder_va_reuses_a_live_checkout_va_from_either_bank(): void
    {
        // A live BSI checkout VA exists for this bill - the reminder must
        // show THAT number, not register a second (Muamalat) VA beside it:
        // two simultaneously live VAs for one bill is exactly the exposure
        // the single-VA reminder was created to remove.
        $live = Payment::create([
            'payment_number' => 'PAY/'.now()->format('Ymd').'/BSI0001',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$this->bill->ulid], 'bank_channel' => 'bsi'],
            'gateway_response' => [
                'provider' => 'bank_bsi',
                'bank_key' => 'bsi',
                'va_number' => '7895012627000700',
                'bank_name' => 'Bank Syariah Indonesia (BSI)',
            ],
        ]);
        PaymentAllocation::create(['payment_id' => $live->id, 'bill_id' => $this->bill->id, 'amount' => 700000]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->never();
        $this->app->instance(BillingApiClient::class, $mockClient);

        $result = app(BillingApiGateway::class)->ensureReminderVa($this->bill->fresh(), $this->guardian);

        $this->assertSame('7895012627000700', $result['va_number']);
        $this->assertSame('bsi', $result['bank']);
        $this->assertSame(1, Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->count());
    }

    public function test_a_reminder_va_settled_by_the_poller_closes_the_bill_once(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->once()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $va = app(BillingApiGateway::class)->ensureReminderVa($this->bill, $this->guardian);

        $mockClient->shouldReceive('getByVaNumber')
            ->with($va['va_number'])
            ->andReturn(['sisa' => 0]);

        $this->artisan('payments:poll-billing-va')->assertSuccessful();

        // The single-VA lane is clean by itself: one payment, one full
        // settlement, no overpayment anywhere.
        $this->assertSame('paid', $this->bill->fresh()->status);
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $this->bill->fresh()->remaining_amount);

        $payment = Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->first();
        $this->assertSame('completed', $payment->status);
        $this->assertArrayNotHasKey('overpayment', $payment->metadata ?? []);
    }
}
