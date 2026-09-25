<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillPdfService;
use App\Services\Billing\BillReminderSender;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\PaymentAllocator;
use App\Services\Notification\NotificationRetryService;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Audit 2026-09-25, lima perbaikan teratas (lihat PROGRESS-MAGANG.md §7):
 * T58 resend Qontak no-op (typo ->data + attempts jalur re-queue), T59 beat
 * reminder tak terbakar saat registrasi VA gagal + resend menolak tagihan
 * tertutup, guard sibling-VA di choke point settle() (pertajam T39), T61
 * rate/tagihan Cambridge per unit tanpa prefix VA, T62 nominal & umur VA.
 */
class AuditSep25FixesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sdUnit;

    private SchoolUnit $raUnit;

    private AcademicYear $year;

    private FeeType $spp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->sdUnit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->raUnit = SchoolUnit::create(['code' => 'RA-1', 'label' => 'RA Sakinah Kebayoran', 'jenjang_group' => 'ra']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        config(['services.qontak.spp_reminder_template_id' => 'tpl-reminder-spp']);
    }

    /** A student with one open SPP bill and a WhatsApp billing contact. */
    private function billedStudent(array $billOverrides = []): Bill
    {
        $student = Student::create([
            'nama_lengkap' => 'Naila Zahra Ramadhani',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $guardian = Guardian::create(['nama' => 'Ibu Naila', 'hubungan' => 'ibu', 'no_hp' => '081200000700']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true, 'is_billing_contact' => true]);

        $bill = Bill::create(array_merge([
            'bill_number' => 'SPP/'.uniqid(),
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:'.uniqid(),
            'description' => 'SPP September 2026',
            'subtotal' => 700000,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => 700000,
            'paid_amount' => 0,
            'remaining_amount' => 700000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'allow_installment' => false,
            'issued_at' => now(),
        ], $billOverrides));

        return $bill->fresh();
    }

    /** The container gateway override BillReminderTest uses, parameterised. */
    private function fakeReminderPair(string $muamalatVa = '8020012627000001', string $bsiVa = '7895012627000001'): void
    {
        $this->app->bind(BillingApiGateway::class, fn () => new class($muamalatVa, $bsiVa) extends BillingApiGateway
        {
            public function __construct(private string $muamalatVa, private string $bsiVa) {}

            public function ensureReminderVaPair(\App\Models\Bill $bill, \App\Models\Guardian $payer): array
            {
                return [
                    'muamalat' => ['va_number' => $this->muamalatVa, 'bank_name' => 'Bank Muamalat'],
                    'bsi' => ['va_number' => $this->bsiVa, 'bank_name' => 'Bank Syariah Indonesia (BSI)'],
                ];
            }
        });
    }

    // --------------------------------------------------------------- T58

    public function test_a_reminder_spp_resend_requeues_the_job_instead_of_faking_sent(): void
    {
        $bill = $this->billedStudent();
        $this->fakeReminderPair();

        $row = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => '081200000700',
            'payload' => ['student_name' => 'Naila Zahra Ramadhani', 'period' => 'September 2026', 'amount' => '700.000'],
            'status' => 'failed',
            'error' => 'Qontak sedang down',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        Queue::fake();

        $result = app(NotificationRetryService::class)->retryOne($row->fresh());

        // The resend queued a real job through the throttled lane...
        $this->assertTrue($result->success);
        Queue::assertPushed(\App\Jobs\SendQontakTemplateMessage::class, 1);

        // ...and the row says QUEUED, not the fake 'sent' the ->data typo
        // used to write (which made the freshly dispatched job skip the
        // delivery as already-sent). The attempt is counted here so the
        // 3-attempt cap still bounds the re-queue lane.
        $row = $row->fresh();
        $this->assertSame('queued', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertNull($row->sent_at);
    }

    // --------------------------------------------------------------- T59

    public function test_a_va_pair_registration_failure_leaves_a_retryable_row_and_does_not_burn_the_beat(): void
    {
        $bill = $this->billedStudent();

        // e-SPP is down exactly when the H-7 sweep tries to register the
        // reminder's VA pair - the audit's "whole cohort's reminder erased
        // silently" scenario.
        $this->app->bind(BillingApiGateway::class, fn () => new class extends BillingApiGateway
        {
            public function __construct() {}

            public function ensureReminderVaPair(\App\Models\Bill $bill, \App\Models\Guardian $payer): array
            {
                throw new \RuntimeException('e-SPP unreachable');
            }
        });

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertFalse($sent);

        // The beat claim stays (a recovered retry must not double-send), but
        // the failure is VISIBLE: a failed reminder_spp row exists for the
        // sweep and the monitoring screen - previously nothing was left at
        // all, and the next day's kind no longer matched, so the reminder
        // was gone forever.
        $this->assertDatabaseCount('bill_reminders', 1);
        $row = NotificationLog::query()->where('template', 'reminder_spp')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('e-SPP unreachable', (string) $row->error);

        // e-SPP recovers; the sweep's second chance rebuilds the VA pair
        // fresh and re-queues through the same throttled job.
        $this->fakeReminderPair($freshMuamalat = '8020012627099999');
        Queue::fake();

        $result = app(NotificationRetryService::class)->retryOne($row->fresh());

        $this->assertTrue($result->success);
        Queue::assertPushed(\App\Jobs\SendQontakTemplateMessage::class, 1);
        Queue::assertPushed(\App\Jobs\SendQontakTemplateMessage::class, fn ($job) => in_array($freshMuamalat, $job->bodyValues));
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'queued', 'attempts' => 2]);
    }

    public function test_a_reminder_spp_resend_refuses_a_bill_that_is_no_longer_open(): void
    {
        $bill = $this->billedStudent(['status' => 'paid', 'remaining_amount' => 0, 'paid_amount' => 700000]);
        $this->fakeReminderPair();

        $row = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => '081200000700',
            'payload' => ['student_name' => 'Naila Zahra Ramadhani'],
            'status' => 'failed',
            'error' => 'Qontak sedang down',
            'notifiable_type' => Bill::class,
            'notifiable_id' => $bill->id,
        ]);

        Queue::fake();

        $result = app(NotificationRetryService::class)->retryOne($row->fresh());

        // A paid bill's reminder must not come back to life from its frozen
        // row - the family would be told to pay a settled bill into a VA
        // the poller has already superseded.
        $this->assertFalse($result->success);
        Queue::assertNotPushed(\App\Jobs\SendQontakTemplateMessage::class);
        $this->assertSame(2, $row->fresh()->attempts);
    }

    // ------------------------------------------- sibling guard (pertajam T39)

    public function test_settling_one_va_supersedes_a_live_sibling_without_a_source_marker(): void
    {
        $bill = $this->billedStudent();

        // The audit's exact hole: a plain checkout VA (NO 'spp_reminder'
        // source marker) reuses as one half of the reminder pair, the other
        // half lives at the other bank. Whichever settles first must kill
        // the sibling - here through the allocator, the choke point the
        // webhook and the poller both settle through.
        $checkout = Payment::create([
            'payment_number' => 'PAY-SEP25-A',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$bill->ulid], 'bank_channel' => 'muamalat'],
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000AA', 'bank_name' => 'Bank Muamalat'],
        ]);

        $sibling = Payment::create([
            'payment_number' => 'PAY-SEP25-B',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$bill->ulid], 'bank_channel' => 'bsi', 'source' => 'spp_reminder'],
            'gateway_response' => ['provider' => 'bank_bsi', 'va_number' => '78950126270000BB', 'bank_name' => 'Bank Syariah Indonesia (BSI)'],
        ]);

        app(PaymentAllocator::class)->allocate($checkout, [$bill->id => 700000]);
        app(PaymentAllocator::class)->allocate($sibling, [$bill->id => 700000]);

        app(PaymentAllocator::class)->settle($checkout, 'cb-sep25-a');

        $this->assertSame('completed', $checkout->fresh()->status);

        // Superseded, not left dangling: a late transfer into the sibling's
        // VA must not be able to settle the same bill a second time.
        $sibling = $sibling->fresh();
        $this->assertSame('failed', $sibling->status);
        $this->assertStringContainsString('Digantikan', (string) $sibling->rejection_reason);

        // And the bill itself closed exactly once.
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(700000.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(1, Payment::where('status', 'completed')->count());
    }

    // --------------------------------------------------------------- T61

    public function test_a_rate_cannot_be_stored_for_a_unit_without_the_fee_types_va_prefix(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        // RA has no Cambridge prefix anywhere (school decision: SD and the
        // two SMPs only) - the rate must be refused for ANY role, not just
        // the unit-scoped one, or billing runs would mint unpayable bills.
        $response = $this->actingAs($this->admin)->postJson('/api/admin/fee-rates', [
            'fee_type_ulid' => $cambridge->ulid,
            'school_unit_ulid' => $this->raUnit->ulid,
            'academic_year_ulid' => $this->year->ulid,
            'amount' => 900000,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($this->raUnit->label, (string) $response->json('message'));
        $this->assertDatabaseCount('fee_rates', 0);

        // The same combination for the SD unit stays allowed.
        $this->actingAs($this->admin)->postJson('/api/admin/fee-rates', [
            'fee_type_ulid' => $cambridge->ulid,
            'school_unit_ulid' => $this->sdUnit->ulid,
            'academic_year_ulid' => $this->year->ulid,
            'amount' => 900000,
        ])->assertCreated();

        // Ekskul's old SD-prefix default for unknown units is gone too: RA
        // ekskul used to silently mint under ekskul_sd.
        $this->assertNull(BillingApiClient::resolvePrefix('ekskul', $this->raUnit));
        $this->assertNotNull(BillingApiClient::resolvePrefix('ekskul', $this->sdUnit));
    }

    public function test_fee_types_list_which_units_cannot_mint_each_type(): void
    {
        FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        $types = $this->actingAs($this->admin)->getJson('/api/admin/fee-types')->assertOk()->json('fee_types');

        $cambridge = collect($types)->first(fn ($t) => $t['code'] === 'cambridge');

        $this->assertNotNull($cambridge);
        $this->assertTrue($cambridge['has_va_prefix']);
        $this->assertContains($this->raUnit->code, $cambridge['units_without_va_prefix']);
        $this->assertNotContains($this->sdUnit->code, $cambridge['units_without_va_prefix']);
    }

    public function test_a_manual_bill_refuses_a_unit_without_the_types_prefix(): void
    {
        $cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        $student = Student::create([
            'nama_lengkap' => 'Anak RA',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->raUnit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/bills/manual', [
            'student_ulid' => $student->ulid,
            'fee_type_ulid' => $cambridge->ulid,
            'description' => 'Cambridge RA',
            'amount' => 500000,
            'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($this->raUnit->label, (string) $response->json('message'));
        $this->assertDatabaseCount('bills', 0);
    }

    // --------------------------------------------------------------- T62

    public function test_reminder_vas_outlive_the_h7_gap(): void
    {
        $bill = $this->billedStudent(['due_date' => now()->addDays(7)]);

        $captured = [];
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->twice()->andReturnUsing(function (array $main, array $bmi, array $bsm) use (&$captured) {
            $captured[] = $main;

            return ['uuid' => 'reminder-va-'.uniqid(), 'status' => 'success'];
        });
        $this->app->instance(BillingApiClient::class, $mockClient);

        app(BillingApiGateway::class)->ensureReminderVaPair($bill, $bill->student->guardians->first());

        // The H-7 beat's VA window must reach the bill's own due date, not
        // die on day 3 while the reminder message is still in the family's
        // chat for four more days.
        $this->assertCount(2, $captured);
        foreach ($captured as $main) {
            $this->assertSame($bill->due_date->toDateString(), $main['date_end']);
        }

        $this->assertTrue(
            Payment::where('expires_at', '>=', $bill->due_date->copy()->startOfDay())->exists(),
            'payment.expires_at harus menjangkau jatuh tempo tagihan'
        );
    }

    public function test_the_pdf_exposes_the_registered_va_amount_when_it_differs_from_the_remaining_balance(): void
    {
        $bill = $this->billedStudent(['total_amount' => 500000, 'subtotal' => 500000, 'remaining_amount' => 500000]);

        // A custom-partial checkout: Rp 300.000 of a Rp 500.000 balance.
        $payment = Payment::create([
            'payment_number' => 'PAY-SEP25-C',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 300000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$bill->ulid], 'bank_channel' => 'muamalat'],
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '80200126270000CC',
                'bank_name' => 'Bank Muamalat',
                'amount' => 300000.0,
            ],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 300000]);

        $va = app(BillPdfService::class)->vaFor($bill->fresh()->load('allocations.payment'));

        // The VA block now carries the amount e-SPP actually registered, so
        // the blade can print "transfer exactly this" instead of letting
        // the paper's bigger Sisa Kewajiban send the family to a rejected
        // transfer.
        $this->assertNotNull($va);
        $this->assertSame('80200126270000CC', $va['number']);
        $this->assertSame(300000.0, $va['amount']);
        $this->assertSame(500000.0, (float) $bill->fresh()->remaining_amount);

        // And the rendered PDF still comes back whole.
        $pdf = app(BillPdfService::class)->render($bill->fresh());
        $this->assertStringContainsString('%PDF', $pdf->output());
    }
}
