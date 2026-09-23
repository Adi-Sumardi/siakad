<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Gelombang 1 dari audit fungsional 2026-09-21. Each test pins one lane the
 * audit found open: pending online checkouts surviving waive/cancel
 * (double-pay), the partial/overdue status flip-flop between the two status
 * writers, pre-generated months landing in the wrong calendar year, the bill
 * PDF printing Muamalat regardless of the family's bank choice, and
 * deactivated accounts keeping access to role-agnostic endpoints. The school
 * also decided payment is VA-only from this day, so the staff cash-recording
 * lane was removed outright rather than guarded.
 */
class AuditWave1FixesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        // Same network-free fake as BillingTest: voidPendingPaymentsFor's
        // bank lookup only runs against the real BillingApiGateway, so the
        // fake exercises the plain "pending payment gets failed" lane.
        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
            {
                $payment->forceFill([
                    'status' => 'processing',
                    'invoice_id' => 'inv_test_'.$payment->id,
                    'invoice_url' => 'https://checkout.test/'.$payment->payment_number,
                ])->save();

                return $payment;
            }
        });

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
    }

    private function rate(float $amount = 650000): FeeRate
    {
        return FeeRate::create([
            'fee_type_id' => $this->spp->id,
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'amount' => $amount,
            'due_day' => 10,
        ]);
    }

    private function student(string $name = 'Aisyah Nur Ramadhani'): Student
    {
        return Student::create([
            'nama_lengkap' => $name,
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ])->fresh();
    }

    private function guardianFor(Student ...$students): User
    {
        $user = User::create([
            'name' => 'Budi Ramadhani',
            'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'email' => $user->email,
        ]);

        foreach ($students as $student) {
            $student->guardians()->attach($guardian->id, [
                'relationship' => 'ayah',
                'is_primary' => true,
                'is_billing_contact' => true,
            ]);
        }

        return $user;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@example.com',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    /** A bill checked out but not yet paid - the invoice is alive at the bank. */
    private function billWithPendingCheckout(): array
    {
        $this->rate();
        $student = $this->student();
        $wali = $this->guardianFor($student);
        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $payment = app(CheckoutService::class)->start($wali, [$bill->ulid], 'virtual_account');

        return [$bill->fresh(), $payment, $this->admin()];
    }

    public function test_waiving_a_bill_fails_its_pending_online_checkout(): void
    {
        [$bill, $payment, $admin] = $this->billWithPendingCheckout();

        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/waive", ['reason' => 'Beasiswa penuh'])
            ->assertOk()
            ->assertJsonPath('bill.status', 'waived');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertStringContainsString('dibebaskan', (string) $payment->fresh()->rejection_reason);
    }

    public function test_cancelling_a_bill_fails_its_pending_online_checkout(): void
    {
        [$bill, $payment, $admin] = $this->billWithPendingCheckout();

        $this->actingAs($admin)
            ->postJson("/api/admin/bills/{$bill->ulid}/cancel", ['reason' => 'Salah terbit'])
            ->assertOk()
            ->assertJsonPath('bill.status', 'cancelled');

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_partial_past_due_stays_overdue_under_both_status_writers(): void
    {
        $this->rate();
        $student = $this->student();
        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $bill->forceFill(['due_date' => now()->subDay()->startOfDay()])->save();

        // Half settled on a bill due yesterday (a custom-amount VA the bank
        // reported paid): still owing, past due.
        $payment = Payment::create([
            'payment_number' => 'PAY-HALF-1',
            'amount' => 325000,
            'method' => 'virtual_account',
            'status' => 'pending',
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 325000]);
        app(PaymentAllocator::class)->settle($payment);

        $this->assertSame('overdue', $bill->fresh()->status, 'allocator menulis overdue untuk partial yang lewat tempo');
        $this->assertEquals(325000.0, (float) $bill->fresh()->paid_amount, 'pembayaran parsial tetap tercatat');

        // The nightly sweep used to be the ONLY writer saying overdue; now it
        // must agree with the allocator instead of fighting it.
        $this->artisan('bills:mark-overdue');

        $this->assertSame('overdue', $bill->fresh()->status);

        // And a later recompute (any payment event) must not flip it back.
        app(PaymentAllocator::class)->recompute($bill->fresh());

        $this->assertSame('overdue', $bill->fresh()->status);

        // Control: a partial on a bill NOT yet due stays partial. Month 10 of
        // TA 2026/2027 is due 2026-10-10 - safely ahead of today.
        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 10);
        $control = Bill::where('period_month', 10)->first();

        app(PaymentAllocator::class)->allocate(
            Payment::create([
                'payment_number' => 'PAY-CTRL-1',
                'amount' => 100000,
                'method' => 'cash',
                'status' => 'completed',
                'paid_at' => now(),
            ]),
            [$control->id => 100000],
        );

        $this->assertSame('partial', $control->fresh()->status);
    }

    public function test_pre_generating_january_lands_in_next_calendar_year(): void
    {
        $this->rate();
        $this->student();

        // TA 2026/2027 starts July 2026; its January belongs to 2027. The old
        // code used now()->year, so pre-generating month 1 while standing in
        // calendar 2026 produced a due date ~11 months in the past - a bill
        // born overdue.
        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 1);

        $bill = Bill::first();

        $this->assertSame(2027, $bill->due_date->year);
        $this->assertSame(1, $bill->due_date->month);
        $this->assertFalse($bill->due_date->isPast(), 'tagihan bulan 1 lahir sudah lewat tempo');
        $this->assertSame('unpaid', $bill->status);
    }

    public function test_bill_pdf_prints_the_bank_the_family_chose(): void
    {
        $this->rate();
        $student = $this->student();
        $wali = $this->guardianFor($student);
        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();

        // No checkout yet: the printout defaults to Muamalat.
        $this->assertSame('muamalat', app(BillPdfService::class)->bankChannelFor($bill->load('allocations.payment')));

        // The family checks out through BSI: the printout must follow.
        app(CheckoutService::class)->start($wali, [$bill->ulid], 'virtual_account', [], 'bsi');

        $bill = $bill->fresh();
        $this->assertSame('bsi', app(BillPdfService::class)->bankChannelFor($bill->load('allocations.payment')));

        // And the rendered PDF itself still comes back whole.
        $pdf = app(BillPdfService::class)->render($bill);
        $this->assertStringContainsString('%PDF', $pdf->output());
    }

    public function test_deactivated_accounts_are_refused_on_role_agnostic_endpoints(): void
    {
        $this->rate();
        $student = $this->student();
        $wali = $this->guardianFor($student);

        // Sanity: the gate is invisible while the account is active.
        $this->actingAs($wali)->getJson('/api/auth/me')->assertOk();
        $this->actingAs($wali)
            ->getJson('/api/files/achievements/01AAAAAAAAAAAAAAAAAAAAAAAAAAAH/sertifikat')
            ->assertStatus(404, 'ULID tak dikenal harus 404 (lulus gate), bukan 403');

        $wali->forceFill(['is_active' => false])->save();

        // /auth/me is the SPA's identity refresh: refusing it here is what
        // turns deactivation into a forced logout on the next page load.
        $this->actingAs($wali)->getJson('/api/auth/me')->assertStatus(403);

        // The private files gate by row ownership, not role - it must still
        // refuse the deactivated session before any ownership check runs.
        $this->actingAs($wali)
            ->getJson('/api/files/achievements/01AAAAAAAAAAAAAAAAAAAAAAAAAAAH/sertifikat')
            ->assertStatus(403);
    }
}
