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
 * The SPP reminder shows a family BOTH banks' VA at once - a real change to
 * this app's normal "one live VA per bill" rule (see CheckoutService's own
 * supersede logic, which this deliberately bypasses). The safety net that
 * makes two simultaneously-live VAs acceptable: the moment either bank's VA
 * settles, the poller immediately supersedes the sibling, so it can never
 * ALSO settle later even if the family pays both by mistake.
 */
class SppReminderDualVaTest extends TestCase
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

    public function test_ensure_reminder_va_pair_registers_both_banks_va_for_one_bill(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->twice()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $result = app(BillingApiGateway::class)->ensureReminderVaPair($this->bill, $this->guardian);

        $studentCode = str_pad((string) $this->student->id, 6, '0', STR_PAD_LEFT);
        $this->assertSame('802001'.'2627'.$studentCode, $result['muamalat']['va_number']);
        $this->assertSame('365601'.'2627'.$studentCode, $result['bsi']['va_number']);
        $this->assertSame('Bank Muamalat', $result['muamalat']['bank_name']);
        $this->assertSame('Bank Syariah Indonesia (BSI)', $result['bsi']['bank_name']);

        // Two separate Payment rows - the real change from the normal
        // one-VA-per-bill model - both alive simultaneously.
        $this->assertSame(2, Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->count());
    }

    public function test_ensure_reminder_va_pair_is_idempotent_across_repeated_reminder_beats(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        // Exactly twice total across BOTH calls below, not four times - the
        // second call (a later reminder beat, e.g. h1 after h7) must reuse
        // the VAs the first call already registered rather than minting new
        // Payment rows (and new e-SPP billing records) every beat.
        $mockClient->shouldReceive('createBilling')->twice()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $gateway = app(BillingApiGateway::class);
        $first = $gateway->ensureReminderVaPair($this->bill, $this->guardian);
        $second = $gateway->ensureReminderVaPair($this->bill->fresh(), $this->guardian);

        $this->assertSame($first['muamalat']['va_number'], $second['muamalat']['va_number']);
        $this->assertSame($first['bsi']['va_number'], $second['bsi']['va_number']);
        $this->assertSame(2, Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->count());
    }

    public function test_poller_supersedes_the_sibling_va_the_moment_one_bank_settles(): void
    {
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->twice()->andReturnUsing(fn () => ['uuid' => 'reminder-va-uuid-'.uniqid(), 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $va = app(BillingApiGateway::class)->ensureReminderVaPair($this->bill, $this->guardian);

        // Family paid Muamalat; BSI's VA is still fully outstanding at e-SPP -
        // the realistic case (paid one, not both), not the theoretical race
        // of both settling in the exact same poll.
        $mockClient->shouldReceive('getByVaNumber')
            ->with($va['muamalat']['va_number'])
            ->andReturn(['sisa' => 0]);
        $mockClient->shouldReceive('getByVaNumber')
            ->with($va['bsi']['va_number'])
            ->andReturn(['sisa' => 700000]);
        // expireVa() on the superseded sibling - best-effort (see its own
        // docblock on why this call is confirmed broken on e-SPP's side
        // today), but the attempt itself must still happen.
        $mockClient->shouldReceive('updateBilling')->once()->andReturn(['status' => 'success']);

        $this->artisan('payments:poll-billing-va')->assertSuccessful();

        $this->assertSame('paid', $this->bill->fresh()->status);

        // Identified by metadata.bank_channel, not gateway_response.va_number -
        // PaymentAllocator::settle() REPLACES gateway_response wholesale with
        // whatever getByVaNumber() returned (here, just {"sisa": 0}, same
        // minimal shape every other test in this suite mocks), so va_number
        // does not necessarily survive settlement. metadata is untouched by
        // settle()/fail(), so it stays a reliable way to tell the two apart.
        $payments = Payment::whereIn('id', PaymentAllocation::where('bill_id', $this->bill->id)->pluck('payment_id'))->get();
        $muamalatPayment = $payments->first(fn (Payment $p) => ($p->metadata['bank_channel'] ?? null) === 'muamalat');
        $bsiPayment = $payments->first(fn (Payment $p) => ($p->metadata['bank_channel'] ?? null) === 'bsi');

        $this->assertNotNull($muamalatPayment);
        $this->assertNotNull($bsiPayment);
        $this->assertSame('completed', $muamalatPayment->status);
        // Superseded, not left dangling as still-pending - a late transfer
        // to this VA must not be able to settle it a second time.
        $this->assertSame('failed', $bsiPayment->status);
    }
}
