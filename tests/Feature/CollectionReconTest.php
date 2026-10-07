<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\PaidBankRecorder;
use App\Services\Reporting\CollectionReconService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Laporan > Recon (2026-10-07, same report as PMB): paid allocations by
 * PAYMENT date, totalled per unit by fee type and bank, listed with the
 * bank's own reference.
 */
class CollectionReconTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $year;

    private FeeType $spp;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));
        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
    }

    private function student(SchoolUnit $unit, string $name): Student
    {
        return Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P', 'school_unit_id' => $unit->id,
            'entry_year_id' => $this->year->id, 'status' => 'active', 'nis' => (string) (1000 + ++$this->n),
        ]);
    }

    private function bill(Student $student, float $amount): Bill
    {
        $this->n++;

        return Bill::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->spp->id,
            'dedup_key' => "recon-{$this->n}", 'bill_number' => "SPP/2026/10/{$this->n}",
            'description' => 'SPP Oktober 2026', 'subtotal' => $amount, 'total_amount' => $amount,
            'remaining_amount' => 0, 'paid_amount' => $amount, 'status' => 'paid', 'due_date' => now(), 'issued_at' => now(),
        ]);
    }

    /** @param  array<int, float>  $shares  bill id => amount */
    private function payment(array $shares, ?string $bank, string $paidAt, string $status = 'completed'): Payment
    {
        $payment = Payment::create([
            'payment_number' => Payment::generateNumber(), 'amount' => array_sum($shares), 'method' => 'virtual_account',
            'status' => $status, 'paid_at' => $status === 'completed' ? $paidAt : null,
        ]);
        $payment->forceFill(['paid_bank' => $bank, 'paid_va' => '7895012627000001', 'paid_reference' => 'REF'.$payment->id])->saveQuietly();

        foreach ($shares as $billId => $amount) {
            PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $billId, 'amount' => $amount]);
        }

        return $payment;
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id', 'role' => $role,
            'school_unit_id' => $unit?->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_it_splits_one_transfer_between_siblings_units_and_counts_by_payment_date(): void
    {
        $kakak = $this->bill($this->student($this->smp, 'Test Kakak'), 700_000);
        $adik = $this->bill($this->student($this->sd, 'Test Adik'), 600_000);

        // One BSI transfer paying both, late on the last day (times are WIB,
        // Siakad's own app timezone).
        $this->payment([$kakak->id => 700_000, $adik->id => 600_000], 'bsi', '2026-10-07 23:30:00');
        // Just after midnight - outside the period.
        $this->payment([$adik->id => 600_000], 'muamalat', '2026-10-08 00:30:00');
        // Not paid: never in a recon.
        $this->payment([$adik->id => 600_000], null, '2026-10-03 03:00:00', 'pending');

        $report = app(CollectionReconService::class)->build(['from' => '2026-10-01', 'to' => '2026-10-07'], $this->staff('admin'));

        $this->assertSame(2, $report['grand']['count']);
        $this->assertSame(1_300_000.0, $report['grand']['amount']);
        $this->assertSame(1_300_000.0, $report['grand']['by_bank']['bsi']['amount']);
        $this->assertSame(0.0, $report['grand']['by_bank']['muamalat']['amount']);
        $this->assertSame(['SD Islam Al Azhar 13', 'SMP Islam Al Azhar 12'], array_column($report['units'], 'unit'));
        $this->assertSame(600_000.0, $report['units'][0]['amount']);
        $this->assertSame('07/10/2026 23:30', $report['units'][0]['rows'][0]['paid_at']);
        // Same transfer, same No. Pembayaran on both units' lines.
        $this->assertSame($report['units'][0]['rows'][0]['payment_number'], $report['units'][1]['rows'][0]['payment_number']);
    }

    public function test_a_unit_admin_gets_only_their_units_share_of_a_shared_transfer(): void
    {
        $kakak = $this->bill($this->student($this->smp, 'Test Kakak'), 700_000);
        $adik = $this->bill($this->student($this->sd, 'Test Adik'), 600_000);
        $this->payment([$kakak->id => 700_000, $adik->id => 600_000], 'bsi', '2026-10-02 03:00:00');

        $report = app(CollectionReconService::class)->build(['from' => '2026-10-01', 'to' => '2026-10-07'], $this->staff('admin_unit', $this->sd));

        $this->assertSame(['SD Islam Al Azhar 13'], array_column($report['units'], 'unit'));
        $this->assertSame(600_000.0, $report['grand']['amount']);
    }

    public function test_it_downloads_as_pdf_and_excel_and_asks_for_the_dates(): void
    {
        $bill = $this->bill($this->student($this->sd, 'Test Unduh'), 600_000);
        $this->payment([$bill->id => 600_000], 'muamalat', now()->subHour()->toDateTimeString());
        $admin = $this->staff('admin');
        $qs = 'from='.now()->subDays(2)->toDateString().'&to='.now()->addDay()->toDateString();

        $this->actingAs($admin)->get("/api/admin/reports/collections/recon/pdf?{$qs}")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $excel = $this->actingAs($admin)->get("/api/admin/reports/collections/recon/excel?{$qs}")->assertOk();
        $this->assertStringStartsWith('PK', $excel->getContent());
        $this->actingAs($admin)->getJson('/api/admin/reports/collections/recon/pdf')
            ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->actingAs($admin)->getJson('/api/admin/reports/collections/recon/options')
            ->assertOk()->assertJsonCount(2, 'units');
    }

    public function test_settlement_keeps_the_va_bank_and_bank_reference_that_the_poll_response_drops(): void
    {
        Http::fake([
            '*/api/login*' => Http::response(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600], 200),
            '*/api/transaction/va/*' => Http::response(['data' => [
                ['billing_uuid' => 'other-month', 'va_number' => '7895012627000001', 'reference_no' => 'WRONG'],
                ['billing_uuid' => 'uuid-oct', 'va_number' => '7895012627000001', 'reference_no' => '001138996801'],
            ]], 200),
        ]);

        $bill = $this->bill($this->student($this->sd, 'Test Lunas'), 600_000);
        $payment = Payment::create([
            'payment_number' => Payment::generateNumber(), 'amount' => 600_000, 'method' => 'virtual_account',
            'status' => 'processing', 'metadata' => ['bank_channel' => 'bsi'],
            'gateway_response' => ['provider' => 'bank_bsi', 'va_number' => '7895012627000001', 'billing_uuid' => 'uuid-oct'],
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 600_000]);

        app(\App\Services\Billing\PaymentAllocator::class)->settle($payment, 'uuid-oct', ['uuid' => 'uuid-oct', 'sisa' => 0]);

        $payment->refresh();
        $this->assertSame('bsi', $payment->paid_bank);
        $this->assertSame('7895012627000001', $payment->paid_va);
        $this->assertSame('001138996801', $payment->paid_reference);
        $this->assertSame('bsi', PaidBankRecorder::bankOfVa('7895012627000001'));
    }
}
