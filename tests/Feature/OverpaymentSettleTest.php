<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The overpayment guard in PaymentAllocator::settle() (audit 2026-10-05).
 *
 * Two VAs for one bill can both be paid before either settles - the
 * reminder pair used to make that state routine, and an abandoned checkout
 * bank-switch still can (expireVa is broken at e-SPP, so the old VA stays
 * payable at the bank). Whatever the lane, the invariant under test is one
 * sentence: a bill is never booked paid twice. The SECOND payment still
 * completes (its money really arrived), but every allocation the bill can
 * no longer absorb is flagged applies_to_bill=false, the excess is recorded
 * on metadata.overpayment, the family gets a dedicated WhatsApp about the
 * pending refund, and the webhook surfaces the case on the monitoring
 * screen instead of swallowing it.
 *
 * The tests build the post-race state directly - first payment completed
 * and the bill recomputed, second payment still claimable - because a
 * strictly sequential double-settle can no longer even reach the guard:
 * settle()'s sibling supersede fails the other pending payment first. The
 * guard exists for the settles that overlap in different processes, where
 * both claims pass before either supersede runs; the bill-row lock inside
 * the claim transaction is what serializes those, and the flag logic these
 * tests exercise is what the lock-order winner leaves the loser to find.
 */
class OverpaymentSettleTest extends TestCase
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

        config(['services.qontak.spp_receipt_template_id' => 'tpl-receipt']);

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

    /** A live VA payment allocated to the bill, on the given bank. */
    private function vaPayment(string $bank, float $amount, string $va): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'PAY-OP-'.strtoupper($bank).'-'.uniqid(),
            'payer_guardian_id' => $this->guardian->id,
            'amount' => $amount,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$this->bill->ulid], 'bank_channel' => $bank],
            'gateway_response' => [
                'provider' => 'bank_'.$bank,
                'bank_key' => $bank,
                'va_number' => $va,
                'bank_name' => $bank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat',
                'billing_uuid' => 'uuid-'.$bank,
            ],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$this->bill->id => $amount]);

        return $payment;
    }

    public function test_the_second_of_two_same_bill_settles_never_double_books(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        $firstResult = app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        // The bill is fully covered; a second VA (still payable at the bank
        // after an abandoned switch, or the concurrent claim that lost the
        // bill lock) settles against zero headroom.
        $second = $this->vaPayment('bsi', 700000, '7895012627000700');
        $secondResult = app(PaymentAllocator::class)->settle($second, 'EXT-SECOND');

        $this->assertTrue($firstResult->claimed);
        $this->assertFalse($firstResult->overpaid);

        $this->assertTrue($secondResult->claimed);
        $this->assertTrue($secondResult->overpaid);
        $this->assertSame(700000.0, $secondResult->excess);

        // Both payments completed - both moved real money - but the bill is
        // booked paid EXACTLY once.
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertSame('paid', $this->bill->fresh()->status);
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $this->bill->fresh()->remaining_amount);

        // The winner counted, the loser flagged.
        $this->assertTrue((bool) $first->allocations()->first()->applies_to_bill);
        $secondAllocation = PaymentAllocation::where('payment_id', $second->id)->sole();
        $this->assertFalse((bool) $secondAllocation->applies_to_bill);

        $this->assertArrayNotHasKey('overpayment', $first->fresh()->metadata ?? []);
        $overpayment = $second->fresh()->metadata['overpayment'];
        $this->assertSame(700000.0, (float) $overpayment['bills'][$this->bill->ulid]['allocation_amount']);
        $this->assertSame(0.0, (float) $overpayment['bills'][$this->bill->ulid]['headroom']);
    }

    public function test_overpayment_sends_one_dedicated_whatsapp_to_the_billing_contact(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        $second = $this->vaPayment('bsi', 700000, '7895012627000700');
        app(PaymentAllocator::class)->settle($second, 'EXT-SECOND');

        // Exactly one free-text WhatsApp lane fired, and it is the
        // overpayment notice - not a receipt, not two messages.
        Queue::assertPushed(\App\Jobs\SendWhatsAppMessage::class, 1);
        Queue::assertPushed(\App\Jobs\SendWhatsAppMessage::class, function ($job) {
            return $job->phone === '081200000700'
                && str_contains($job->message, 'PEMBAYARAN GANDA')
                && str_contains($job->message, 'refund')
                && str_contains($job->message, '700.000');
        });

        $log = \App\Models\NotificationLog::query()
            ->where('template', 'payment_overpayment')
            ->where('channel', 'whatsapp')
            ->sole();
        $this->assertSame('queued', $log->status);
        $this->assertSame(Payment::class, $log->notifiable_type);
        $this->assertSame($second->id, $log->notifiable_id);

        // Both payments still get their normal receipts - both moved money,
        // and neither receipt is the place to explain the refund.
        $this->assertSame(2, \App\Models\NotificationLog::where('template', 'receipt_spp_school')->count());
    }

    public function test_overpayment_notifier_is_idempotent_when_settle_re_runs(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        $second = $this->vaPayment('bsi', 700000, '7895012627000700');
        app(PaymentAllocator::class)->settle($second, 'EXT-SECOND');

        // A redelivered callback settles the same row again: not claimed,
        // nothing re-sent, still exactly one overpayment notice.
        $replay = app(PaymentAllocator::class)->settle($second, 'EXT-SECOND-REPLAY');

        $this->assertFalse($replay->claimed);
        $this->assertFalse($replay->overpaid);
        $this->assertSame(0.0, $replay->excess);
        $this->assertSame(1, \App\Models\NotificationLog::where('template', 'payment_overpayment')->count());
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);
    }

    public function test_webhook_marks_the_integration_event_failed_on_overpayment(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        $second = $this->vaPayment('bsi', 700000, '7895012627000700');

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('7895012627000700')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->postJson('/api/payment-webhook/route-uuid-op', [
            'billing_uuid' => 'uuid-bsi',
            'uuid' => 'evt-overpayment-4321',
            'reference_no' => '7895012627000700',
            'jumlah_pembayaran' => 700000,
        ]);

        $response->assertOk();

        // The money is booked exactly once, the second payment completed
        // with its overpayment flagged...
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertArrayHasKey('overpayment', $second->fresh()->metadata);
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);

        // ...and the ruang kontrol sees it: the event stays 'failed' with a
        // message a human can act on, not a quiet 'processed'.
        $event = IntegrationEvent::where('event_id', 'billing_api:evt-overpayment-4321')->sole();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('ganda', (string) $event->error);
        $this->assertStringContainsString('refund', (string) $event->error);
    }

    public function test_poller_settles_a_second_va_and_books_the_bill_once(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        $second = $this->vaPayment('bsi', 700000, '7895012627000700');

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('7895012627000700')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->artisan('payments:poll-billing-va')->assertSuccessful();

        $this->assertSame('paid', $this->bill->fresh()->status);
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);

        $this->assertSame('completed', $second->fresh()->status);
        $this->assertArrayHasKey('overpayment', $second->fresh()->metadata);
        $this->assertFalse((bool) PaymentAllocation::where('payment_id', $second->id)->sole()->applies_to_bill);
        $this->assertTrue((bool) PaymentAllocation::where('payment_id', $first->id)->sole()->applies_to_bill);
    }

    public function test_two_partial_payments_apply_and_a_third_flags_only_the_excess_row(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 350000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-1');

        $this->assertSame('partial', $this->bill->fresh()->status);
        $this->assertSame(350000.0, (float) $this->bill->fresh()->paid_amount);

        $second = $this->vaPayment('bsi', 350000, '7895012627000700');
        $secondResult = app(PaymentAllocator::class)->settle($second, 'EXT-2');

        // Headroom was exactly 350k - the second payment still counts.
        $this->assertFalse($secondResult->overpaid);
        $this->assertSame('paid', $this->bill->fresh()->status);

        // A third payment on a fully-paid bill is pure excess.
        $third = $this->vaPayment('muamalat', 350000, '8020012627000800');
        $thirdResult = app(PaymentAllocator::class)->settle($third, 'EXT-3');

        $this->assertTrue($thirdResult->overpaid);
        $this->assertSame(350000.0, $thirdResult->excess);
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $this->bill->fresh()->remaining_amount);
        $this->assertFalse((bool) PaymentAllocation::where('payment_id', $third->id)->sole()->applies_to_bill);
    }

    public function test_report_by_fee_type_excludes_unapplied_allocations_but_total_counts_the_cash(): void
    {
        Queue::fake();

        $first = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($first, 'EXT-FIRST');

        $second = $this->vaPayment('bsi', 700000, '7895012627000700');
        app(PaymentAllocator::class)->settle($second, 'EXT-SECOND');

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-overpayment@yapinet.id',
            'role' => 'admin',
            'school_unit_id' => $this->unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/reports/collections');

        $response->assertOk();
        $json = $response->json();

        // The cash really moved twice - 'total' says so...
        $this->assertSame(1400000.0, (float) $json['total']);
        $this->assertSame(2, $json['count']);

        // ...but fee-type attribution counts what settled bills, once.
        $this->assertCount(1, $json['by_fee_type']);
        $this->assertSame('SPP', $json['by_fee_type'][0]['fee_type']);
        $this->assertSame(700000.0, (float) $json['by_fee_type'][0]['total']);
    }

    public function test_settle_returns_not_claimed_for_an_already_completed_payment(): void
    {
        Queue::fake();

        $only = $this->vaPayment('muamalat', 700000, '8020012627000700');
        app(PaymentAllocator::class)->settle($only, 'EXT-A');

        $result = app(PaymentAllocator::class)->settle($only, 'EXT-A-AGAIN');

        $this->assertFalse($result->claimed);
        $this->assertFalse($result->overpaid);
        $this->assertSame(0.0, $result->excess);
        $this->assertSame('EXT-A', $only->fresh()->external_transaction_id, 'id pemenang klaim tidak tertimpa');
        $this->assertSame(700000.0, (float) $this->bill->fresh()->paid_amount);
    }
}
