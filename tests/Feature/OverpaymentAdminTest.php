<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\Payment;
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
 * The TU-facing half of the overpayment handling (audit 2026-10-05 P0):
 * the refund worklist, the one-shot refund decision, and the monitoring
 * row for money that lands on a VA nobody was watching anymore. The
 * allocator-side flagging these build on is covered by
 * OverpaymentSettleTest.
 */
class OverpaymentAdminTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private FeeType $spp;

    private Student $student;

    private Guardian $guardian;

    private Bill $billA;

    private Bill $billB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.qontak.spp_receipt_template_id' => 'tpl-receipt']);

        $this->unit = SchoolUnit::create(['code' => 'SD-14', 'label' => 'SD Islam Al Azhar 14', 'jenjang_group' => 'sd', 'is_active' => true]);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $this->student = Student::create([
            'nama_lengkap' => 'Ahmad Fauzan',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $year->id,
            'nis' => '701',
        ]);
        $this->guardian = Guardian::create(['nama' => 'Bapak Fauzan', 'hubungan' => 'ayah', 'no_hp' => '081200000701']);
        $this->student->guardians()->attach($this->guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $makeBill = function (string $month, string $number) use ($year): Bill {
            return Bill::create([
                'bill_number' => $number,
                'dedup_key' => 'spp:2026:'.$month.':'.$this->student->id,
                'description' => 'SPP Bulan '.$month.' 2026',
                'student_id' => $this->student->id,
                'academic_year_id' => $year->id,
                'fee_type_id' => $this->spp->id,
                'subtotal' => 700000,
                'total_amount' => 700000,
                'remaining_amount' => 700000,
                'status' => 'unpaid',
                'due_date' => now()->addDays(7)->toDateString(),
                'issued_at' => now(),
            ]);
        };

        $this->billA = $makeBill('09', 'SPP/2026/09/00701');
        $this->billB = $makeBill('10', 'SPP/2026/10/00701');
    }

    private function vaPayment(float $amount, array $allocations, string $va, string $bank = 'muamalat'): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'PAY-OA-'.strtoupper($bank).'-'.uniqid(),
            'payer_guardian_id' => $this->guardian->id,
            'amount' => $amount,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bank_channel' => $bank],
            'gateway_response' => [
                'provider' => 'bank_'.$bank,
                'bank_key' => $bank,
                'va_number' => $va,
                'bank_name' => 'Bank Muamalat',
            ],
        ]);
        app(PaymentAllocator::class)->allocate($payment, $allocations);

        return $payment;
    }

    private function admin(string $role, ?SchoolUnit $unit = null): User
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

    public function test_the_refund_worklist_lists_only_flagged_payments_and_scopes_per_unit(): void
    {
        Queue::fake();

        $first = $this->vaPayment(700000, [$this->billA->id => 700000], '8020012627000701');
        app(PaymentAllocator::class)->settle($first, 'EXT-1');

        $second = $this->vaPayment(700000, [$this->billA->id => 700000], '7895012627000701', 'bsi');
        app(PaymentAllocator::class)->settle($second, 'EXT-2');

        // A normal payment (the one that actually settled billB) must NOT
        // appear - the worklist is "what TU owes back", nothing else.
        $normal = $this->vaPayment(700000, [$this->billB->id => 700000], '8020012627000801');
        app(PaymentAllocator::class)->settle($normal, 'EXT-3');

        $response = $this->actingAs($this->admin('admin'))->getJson('/api/admin/payments/overpayments');

        $response->assertOk();
        $ids = collect($response->json('payments.data'))->pluck('ulid')->all();
        $this->assertSame([$second->ulid], $ids);

        // A unit admin from a DIFFERENT unit sees nothing.
        $otherUnit = SchoolUnit::create(['code' => 'SD-99', 'label' => 'SD Lain', 'jenjang_group' => 'sd']);
        $this->actingAs($this->admin('admin_unit', $otherUnit))
            ->getJson('/api/admin/payments/overpayments')
            ->assertOk()
            ->assertJsonCount(0, 'payments.data');
    }

    public function test_refund_marks_a_pure_overpayment_refunded_and_keeps_the_bill_untouched(): void
    {
        Queue::fake();

        $first = $this->vaPayment(700000, [$this->billA->id => 700000], '8020012627000701');
        app(PaymentAllocator::class)->settle($first, 'EXT-1');

        $second = $this->vaPayment(700000, [$this->billA->id => 700000], '7895012627000701', 'bsi');
        app(PaymentAllocator::class)->settle($second, 'EXT-2');

        $response = $this->actingAs($this->admin('admin'))->postJson("/api/admin/payments/{$second->ulid}/refund");

        $response->assertOk();

        $fresh = $second->fresh();
        $this->assertSame('refunded', $fresh->status);
        $this->assertArrayNotHasKey('overpayment', $fresh->metadata);
        $this->assertArrayHasKey('refunded', $fresh->metadata);
        $this->assertSame(700000.0, (float) $fresh->metadata['refunded']['overpayment']['bills'][$this->billA->ulid]['allocation_amount']);

        // The bill's arithmetic never depended on the unapplied allocation.
        $this->assertSame('paid', $this->billA->fresh()->status);
        $this->assertSame(700000.0, (float) $this->billA->fresh()->paid_amount);

        // Double refund is refused - the decision is one-shot.
        $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/payments/{$second->ulid}/refund")
            ->assertStatus(422);
    }

    public function test_refund_refuses_a_payment_that_partially_settled_a_bill(): void
    {
        Queue::fake();

        // BillA fully covered first...
        $first = $this->vaPayment(700000, [$this->billA->id => 700000], '8020012627000701');
        app(PaymentAllocator::class)->settle($first, 'EXT-1');

        // ...then one basket pays billA (now covered - flagged) AND the open
        // billB (applies): refunding it whole would reopen billB.
        $basket = $this->vaPayment(1400000, [$this->billA->id => 700000, $this->billB->id => 700000], '8020012627000801');
        $result = app(PaymentAllocator::class)->settle($basket, 'EXT-2');

        $this->assertTrue($result->overpaid);

        $this->actingAs($this->admin('admin'))
            ->postJson("/api/admin/payments/{$basket->ulid}/refund")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran ini sebagian melunasi tagihan - refund parsial harus diproses manual oleh pusat.');

        $this->assertSame('completed', $basket->fresh()->status);
    }

    public function test_a_surprise_late_payment_lands_on_the_monitoring_screen(): void
    {
        $payment = $this->vaPayment(700000, [$this->billA->id => 700000], '8020012627000701');
        app(PaymentAllocator::class)->fail($payment, 'failed', 'Digantikan oleh checkout baru.');

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('8020012627000701')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        app(BillingApiGateway::class)->checkForSurpriseLatePayment($payment->fresh());
        app(BillingApiGateway::class)->checkForSurpriseLatePayment($payment->fresh());

        // One stable row, marked failed for the ruang kontrol - not a daily
        // stream of duplicates, and not only a rotating laravel.log line.
        $event = IntegrationEvent::query()
            ->where('event_type', 'payment.surprise_late')
            ->sole();

        $this->assertSame('failed', $event->status);
        $this->assertSame('billing_api:surprise:'.$payment->ulid.':8020012627000701', $event->event_id);
        $this->assertStringContainsString('rekonsiliasi manual', (string) $event->error);
        $this->assertSame(1, $event->attempts, 'panggilan ulang tidak menaikkan attempts tanpa batas');
    }
}
