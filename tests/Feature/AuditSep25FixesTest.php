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
use App\Services\Billing\CheckoutService;
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

    // --------------------------------------------------------------- T60

    public function test_resetting_via_email_clears_the_guardian_phone_blind_index(): void
    {
        $user = User::create([
            'name' => 'Wali Reset Email',
            'email' => 'lama@yapinet.id',
            'phone' => '081200000099',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
            'no_hp' => '081200000099',
            'email' => 'lama@yapinet.id',
        ]);

        // Sanity: the blind index resolves before the reset.
        $this->assertNotNull(Guardian::findByEncrypted('no_hp', '081200000099'));

        $token = \App\Models\AccountInvitation::generateToken();

        \App\Models\AccountInvitation::create([
            'user_id' => $user->id,
            'token_hash' => \App\Models\AccountInvitation::hashToken($token),
            'channel' => 'email',
            'sent_to' => 'baru@yapinet.id',
            'purpose' => 'reset',
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson("/api/invitations/{$token}/activate")->assertOk();

        $this->assertSame('baru@yapinet.id', $user->fresh()->email);
        $this->assertNull($user->fresh()->phone);

        // The mirror followed the reset - and the dead phone's blind index
        // is GONE. The stale hash used to match the next PMB handoff's
        // lookup, which then wrote the revoked number straight back onto
        // the guardian (audit T60-a).
        $guardian = $guardian->fresh();
        $this->assertSame('baru@yapinet.id', $guardian->email);
        $this->assertNull($guardian->no_hp);
        $this->assertNull(\DB::table('guardians')->where('id', $guardian->id)->value('no_hp_hash'));
        $this->assertNull(Guardian::findByEncrypted('no_hp', '081200000099'));
    }

    public function test_editing_a_parent_phone_mirrors_to_the_guardian(): void
    {
        $user = User::create([
            'name' => 'Wali Ganti HP',
            'email' => 'ganti@yapinet.id',
            'phone' => '081200000099',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
            'no_hp' => '081200000099',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->ulid}", ['phone' => '081299900022'])
            ->assertOk();

        // OTP login reads users.phone, but reminders/VA notices/receipts all
        // read guardians.no_hp - both homes must carry the new number
        // (audit T60-b).
        $this->assertSame('081299900022', $user->fresh()->phone);
        $this->assertSame('081299900022', $guardian->fresh()->no_hp);
    }

    // --------------------------------------------------------------- T63

    public function test_the_watchlist_only_considers_active_students(): void
    {
        $active = Student::create([
            'nama_lengkap' => 'Siswa Aktif', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $graduated = Student::create([
            'nama_lengkap' => 'Alumni Semester Ini', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'graduated',
        ]);

        // Both carry a point violation from this term - only the active
        // student belongs on the "Perlu Perhatian" population.
        $points = collect([
            (object) ['student_id' => $active->id, 'points' => -10],
            (object) ['student_id' => $graduated->id, 'points' => -10],
        ]);

        $flagged = app(\App\Services\Academic\WatchlistService::class)
            ->identify(collect([$active, $graduated]), collect(), collect(), $points, null, null);

        $this->assertTrue($flagged->has($active->id));
        $this->assertFalse($flagged->has($graduated->id), 'alumni tidak boleh masuk populasi perlu-perhatian');
    }

    public function test_a_status_change_records_when_it_happened(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa Keluar', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $this->assertNull($student->status_changed_at);

        // Whichever lane writes the transition (promotion, admin edit, PMB,
        // CSV import), the model itself stamps it (audit T63-c).
        $student->forceFill(['status' => 'transferred'])->save();

        $this->assertNotNull($student->fresh()->status_changed_at);
    }

    public function test_the_admin_lane_cannot_assign_an_inactive_or_off_year_ekskul(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa Ekskul', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $inactive = \App\Models\Extracurricular::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'name' => 'Futsal', 'is_active' => false,
        ]);

        // The schema itself seeds the two upcoming academic years
        // (2026_08_20_000031) - reuse one instead of colliding with it.
        $otherYear = AcademicYear::where('year', '2027/2028')->firstOrFail();
        $offYear = \App\Models\Extracurricular::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $otherYear->id,
            'name' => 'Robotik', 'is_active' => true,
        ]);

        $graduated = Student::create([
            'nama_lengkap' => 'Alumni Ekskul', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'graduated',
        ]);

        $healthy = \App\Models\Extracurricular::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'name' => 'Dokter Kecil', 'is_active' => true,
        ]);

        $service = app(\App\Services\Academic\ExtracurricularService::class);

        try {
            $service->assignStudent($inactive, $student, $this->admin);
            $this->fail('ekskul nonaktif harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak aktif', $e->getMessage());
        }

        try {
            $service->assignStudent($offYear, $student, $this->admin);
            $this->fail('ekskul tahun lain harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tahun ajaran', $e->getMessage());
        }

        try {
            $service->assignStudent($healthy, $graduated, $this->admin);
            $this->fail('siswa non-aktif harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bukan siswa aktif', $e->getMessage());
        }

        $this->assertDatabaseCount('extracurricular_members', 0);
    }

    // --------------------------------------------------------------- T64

    public function test_only_one_job_can_claim_a_delivery(): void
    {
        $row = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'bill_reminder',
            'recipient' => '081200000700',
            'payload' => [],
            'status' => 'queued',
        ]);

        // The sweep's re-queue racing the original job's backoff: the first
        // conditional UPDATE wins, the second is refused without a send
        // (audit T64-b) - the read-then-send guard let both through.
        $this->assertTrue(NotificationLog::claimDelivery($row->ulid));
        $this->assertFalse(NotificationLog::claimDelivery($row->ulid));
        $this->assertNotNull($row->fresh()->claimed_at);

        // Writing the outcome releases the claim, so the job's own retry
        // (or the sweep's) re-claims cleanly; a sent row never claims
        // again, and callers without a row send as before.
        $row->fresh()->forceFill(['status' => 'failed', 'claimed_at' => null])->save();
        $this->assertTrue(NotificationLog::claimDelivery($row->ulid));
        $row->fresh()->forceFill(['status' => 'sent', 'claimed_at' => null])->save();
        $this->assertFalse(NotificationLog::claimDelivery($row->ulid));

        // A stale claim (worker died hard mid-send) expires on its own.
        $row->fresh()->forceFill(['status' => 'queued', 'claimed_at' => now()->subMinutes(31)])->save();
        $this->assertTrue(NotificationLog::claimDelivery($row->ulid));
        $this->assertTrue(NotificationLog::claimDelivery(null));
    }

    public function test_the_sweep_picks_up_a_row_whose_claim_went_stale(): void
    {
        $stuck = NotificationLog::create([
            'channel' => 'email',
            'template' => 'bill_reminder',
            'recipient' => 'x@example.com',
            'payload' => ['kind' => 'h7'],
            'status' => 'queued',
            'notifiable_type' => Bill::class,
            'notifiable_id' => 999999,
        ]);
        $stuck->forceFill(['claimed_at' => now()->subMinutes(31)])->saveQuietly();

        $live = NotificationLog::create([
            'channel' => 'email',
            'template' => 'bill_reminder',
            'recipient' => 'y@example.com',
            'payload' => ['kind' => 'h7'],
            'status' => 'queued',
            'claimed_at' => now(),
            'notifiable_type' => Bill::class,
            'notifiable_id' => 999999,
        ]);

        $due = app(NotificationRetryService::class)->due();

        // A worker that died hard leaves a fresh claim behind with no
        // outcome - once stale, the row re-enters the retry lane; a freshly
        // claimed row is still somebody's in-flight delivery and an
        // untouched queued row belongs to its original job.
        $this->assertTrue($due->contains('ulid', $stuck->ulid));
        $this->assertFalse($due->contains('ulid', $live->ulid));
    }

    public function test_a_log_only_send_is_flagged_on_the_row(): void
    {
        config([
            'services.qontak.spp_reminder_template_id' => 'tpl-reminder-spp',
            'services.qontak.base_url' => null,
            'services.qontak.channel_integration_id' => null,
            'services.qontak.client_id' => null,
            'services.qontak.client_secret' => null,
        ]);

        $row = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'reminder_spp',
            'recipient' => '081200000700',
            'payload' => [],
            'status' => 'queued',
        ]);

        (new \App\Jobs\SendQontakTemplateMessage(
            phone: '081200000700',
            toName: 'Ibu Naila',
            templateId: 'tpl-reminder-spp',
            bodyValues: ['Naila', 'September 2026', '700.000', '8020012627999', '012627999'],
            notificationLogUlid: $row->ulid,
        ))->handle(app(\App\Services\Notification\QontakWhatsAppGateway::class));

        // Status stays 'sent' (behaviour unchanged), but the error column
        // says the message never physically left the building - a
        // misconfigured production box no longer looks fully green
        // (audit T64-a).
        $fresh = $row->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertStringContainsString('log-only', (string) $fresh->error);
    }

    public function test_a_double_settle_sends_the_whatsapp_receipt_once(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'tpl-receipt']);
        Queue::fake();

        $bill = $this->billedStudent();

        $payment = Payment::create([
            'payment_number' => 'PAY-SEP25-R',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000RR', 'bank_name' => 'Bank Muamalat'],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        $sender = app(\App\Services\Billing\PaymentReceiptSender::class);

        // The webhook and a stale poller snapshot can both land here for one
        // payment (audit T64-c) - only one receipt row may exist.
        $sender->send($payment);
        $sender->send($payment);

        $this->assertSame(
            1,
            NotificationLog::where('template', 'receipt_spp_school')->count(),
            'kuitansi WhatsApp harus idempoten per pembayaran'
        );
    }

    // --------------------------------------------------------------- T66

    public function test_an_unknown_identifier_double_tap_throttles_like_a_known_one(): void
    {
        // First tap: the generic 200, same as always for an unknown account.
        $this->postJson('/api/auth/otp/request', ['identifier' => 'tidak.ada@yapinet.id'])
            ->assertOk()
            ->assertJsonStructure(['channel', 'identifier', 'expires_in_minutes', 'resend_after_seconds']);

        // Second tap inside the cooldown: 429 with the SAME message shape a
        // real account gets. The old flat-200-here vs 429-for-real accounts
        // differential answered "does this family attend the school"
        // without ever needing the code (audit T66-a).
        $this->postJson('/api/auth/otp/request', ['identifier' => 'tidak.ada@yapinet.id'])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after_seconds']);
    }

    public function test_wrong_guesses_can_never_push_attempts_past_the_cap(): void
    {
        $user = User::create([
            'name' => 'Wali Tebak Kode',
            'email' => 'tebak@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        app(\App\Services\Auth\OtpService::class)->issue($user, 'tebak@yapinet.id');

        $otp = \App\Models\LoginOtp::latest('id')->first();

        // Ten wrong guesses, sequentially: the column must land exactly on
        // the cap and never overshoot - the increment is conditional now,
        // so even guesses that all read the same pre-increment value (the
        // parallel case) cannot each earn a try (audit T66-c).
        $service = app(\App\Services\Auth\OtpService::class);

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($service->verify('tebak@yapinet.id', '999999'));
        }

        $this->assertSame(5, $otp->fresh()->attempts);
        $this->assertFalse($otp->fresh()->isUsable());
    }

    // --------------------------------------------------------------- T65

    /** An open daily session of the given type, dated today. */
    private function openDailySession(string $type = 'masuk'): \App\Models\DailySession
    {
        return \App\Models\DailySession::create([
            'school_unit_id' => $this->sdUnit->id,
            'date' => now('Asia/Jakarta')->toDateString(),
            'type' => $type,
            'opens_at' => now('Asia/Jakarta')->subHour(),
            'closes_at' => now('Asia/Jakarta')->addHours(2),
            'status' => 'open',
        ]);
    }

    public function test_a_pulang_session_refuses_morning_absence_statuses(): void
    {
        \App\Models\Term::create([
            'academic_year_id' => $this->year->id,
            'name' => 'ganjil',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ])->activate();

        $student = Student::create([
            'nama_lengkap' => 'Siswa Pulang Cepat', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $pulang = $this->openDailySession('pulang');
        $service = app(\App\Services\Attendance\DailyAttendanceService::class);

        try {
            $service->mark($pulang, $student, 'sakit', $this->admin, 'tu');
            $this->fail('status pagi harus ditolak di sesi pulang');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sesi pulang', $e->getMessage());
        }

        // Hadir stays valid on both windows.
        $service->mark($pulang, $student, 'hadir', $this->admin, 'tu');
        $this->assertDatabaseHas('daily_records', ['daily_session_id' => $pulang->id, 'attendance_status' => 'hadir']);
    }

    public function test_removing_today_from_active_days_closes_the_open_window_quietly(): void
    {
        // Every day EXCEPT today (audit T65-c): the unit just declared today
        // is not an attendance day.
        $today = (int) now('Asia/Jakarta')->dayOfWeekIso;
        $days = collect([1, 2, 3, 4, 5])->reject(fn ($d) => $d === $today)->values()->all();

        $setting = \App\Models\DailyAttendanceSetting::create([
            'school_unit_id' => $this->sdUnit->id,
            'enabled' => true,
            'days' => $days,
            'masuk_opens_at' => '06:30',
            'masuk_closes_at' => '08:00',
            'pulang_enabled' => false,
            'intake_mode' => 'wali_kelas',
        ]);

        $session = $this->openDailySession('masuk');

        app(\App\Services\Attendance\DailyAttendanceService::class)
            ->resyncTodayWindows($setting, $this->admin);

        // The window closes quietly - and no auto-alpa is invented for a day
        // the unit itself withdrew, so the next sweep tick finds nothing to
        // sweep.
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertDatabaseCount('daily_records', 0);
    }

    public function test_a_same_day_unit_move_is_refused_while_a_daily_mark_is_live(): void
    {
        \App\Models\Term::create([
            'academic_year_id' => $this->year->id,
            'name' => 'ganjil',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ])->activate();

        // No enrollment - the promotion-lane guard passes; only the
        // same-day mark holds the move back (audit T65-a).
        $student = Student::create([
            'nama_lengkap' => 'Siswa Pindah Hari Ini', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $session = $this->openDailySession('masuk');
        \App\Models\DailyRecord::create([
            'daily_session_id' => $session->id,
            'student_id' => $student->id,
            'term_id' => \App\Models\Term::current()->id,
            'date' => now('Asia/Jakarta')->toDateString(),
            'attendance_status' => 'hadir',
            'source' => 'tu',
            'checked_in_at' => now('Asia/Jakarta'),
            'record_status' => 'recorded',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$student->ulid}", ['school_unit_ulid' => $this->raUnit->ulid])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'presensi'));

        // Without the live mark, the same move goes through.
        \App\Models\DailyRecord::query()->update(['record_status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'uji']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$student->ulid}", ['school_unit_ulid' => $this->raUnit->ulid])
            ->assertOk();
    }

    public function test_an_overlapping_pulang_window_is_refused_up_front(): void
    {
        $response = $this->actingAs($this->admin)->patchJson('/api/admin/daily-attendance/settings', [
            'unit' => $this->sdUnit->ulid,
            'enabled' => true,
            'days' => [1, 2, 3, 4, 5],
            'masuk_opens_at' => '06:30',
            'masuk_closes_at' => '08:00',
            'pulang_enabled' => true,
            'pulang_opens_at' => '07:30',
            'pulang_closes_at' => '14:00',
        ]);

        // pulang opening before masuk closes made the gate serve MASUK for
        // the whole overlap and gutted the device-once cross-window rule
        // (audit T65-b) - refused at validation now.
        $response->assertStatus(422)->assertInvalid('pulang_opens_at');
    }

    // --------------------------------------------------------------- T67

    public function test_an_announcement_cannot_pair_a_foreign_units_classroom(): void
    {
        $classroom = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id,
            'academic_year_id' => $this->year->id,
            'tingkat' => 1,
            'name' => '1-A',
            'is_active' => true,
        ]);

        // RA's unit code beside SD's classroom: the stored scope used to say
        // one thing to staff and another to families (audit T67-h).
        $this->actingAs($this->admin)
            ->postJson('/api/admin/announcements', [
                'title' => 'Pengumuman Silang',
                'body' => 'Unit B dengan kelas unit A.',
                'school_unit_code' => $this->raUnit->code,
                'classroom_ulid' => $classroom->ulid,
            ])
            ->assertStatus(422);
    }

    public function test_a_custom_checkout_below_ten_thousand_is_refused(): void
    {
        $bill = $this->billedStudent();
        $guardian = $bill->student->guardians->first();

        $wali = User::create([
            'name' => 'Wali Checkout Kecil',
            'role' => 'orangtua',
            'is_active' => true,
        ]);
        $guardian->forceFill(['user_id' => $wali->id])->save();

        try {
            app(CheckoutService::class)->start($wali, [$bill->ulid], 'virtual_account', [$bill->ulid => 5000]);
            $this->fail('nominal kustom di bawah Rp 10.000 harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rp 10.000', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_expired_va_is_still_watched_by_the_surprise_late_payment_net(): void
    {
        $this->app->bind(BillingApiClient::class, function () {
            return new class extends BillingApiClient
            {
                public function __construct() {}

                public function getByVaNumber(string $vaNumber): array
                {
                    return ['sisa' => 0];
                }
            };
        });

        // A payment the poller itself stamped 'expired' - money landing on it
        // must surface exactly like it does for a failed VA (audit T67-e).
        Payment::create([
            'payment_number' => 'PAY-SEP25-EXP',
            'amount' => 100000,
            'method' => 'virtual_account',
            'status' => 'expired',
            'failed_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-SEP25-EXP'],
        ]);

        // One pending row keeps the command past its empty-queue early return.
        Payment::create([
            'payment_number' => 'PAY-SEP25-EXP-P',
            'amount' => 100000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-SEP25-EXP-P'],
        ]);

        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('payments:poll-billing-va')->assertSuccessful();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('critical')->once();
    }

    // --------------------------------------------------------------- T47

    public function test_two_active_academic_years_are_refused_by_the_database(): void
    {
        // setUp already created the active 2026/2027. A second active year
        // (two admin tabs racing activate(), or a hand-edited row) used to
        // stand - now the partial unique index refuses it at the engine
        // (audit T47).
        $this->expectException(\Illuminate\Database\QueryException::class);

        AcademicYear::create([
            'year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30', 'is_active' => true,
        ]);
    }

    public function test_two_active_terms_are_refused_by_the_database(): void
    {
        \App\Models\Term::create([
            'academic_year_id' => $this->year->id,
            'name' => 'ganjil',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ])->activate();

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Models\Term::create([
            'academic_year_id' => $this->year->id,
            'name' => 'genap',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);
    }

    public function test_a_term_of_an_inactive_year_cannot_be_activated(): void
    {
        // The seeded upcoming year (2027/2028) is inactive by default.
        $term = \App\Models\Term::create([
            'academic_year_id' => AcademicYear::where('year', '2027/2028')->firstOrFail()->id,
            'name' => 'ganjil',
            'starts_on' => '2027-07-01',
            'ends_on' => '2027-12-31',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/terms/{$term->ulid}/activate")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'tahun ajaran'));

        $this->assertFalse((bool) $term->fresh()->is_active);
    }

    public function test_term_names_are_shape_checked_against_the_enum(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/terms', [
            'academic_year_ulid' => $this->year->ulid,
            'name' => 'Ganjil',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ]);

        // Free text used to validate fine and then die as a 500 on the DB's
        // enum('ganjil','genap') column (audit T47).
        $response->assertStatus(422)->assertInvalid('name');
    }

    // --------------------------------------------------------------- T48

    private function csvUpload(string $content, string $name = 'import.csv'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\Testing\File::fake()->createWithContent($name, $content);
    }

    public function test_reimporting_an_old_roster_never_resurrects_alumni(): void
    {
        $adminUnit = User::create([
            'name' => 'Admin Unit SD',
            'role' => 'admin_unit',
            'school_unit_id' => $this->sdUnit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Alumni Terimport',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'graduated',
        ]);

        \App\Models\Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'classroom_id' => \App\Models\Classroom::create([
                'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
                'tingkat' => 6, 'name' => '6-A', 'is_active' => true,
            ])->id,
            'status' => 'graduated',
            'joined_on' => '2026-07-01',
        ]);

        $csv = "nama_lengkap,jenis_kelamin,kelas,status\nAlumni Terimport,L,6-A,active\n";

        $response = $this->actingAs($adminUnit)
            ->post('/api/admin/import/students', ['file' => $this->csvUpload($csv)])
            ->assertOk();

        // The row is refused and names the state - the old updateOrCreate
        // flipped the enrollment back to 'active' and the student back to
        // 'active' with it (audit T48-a).
        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString('graduated', (string) $response->json('errors.0'));

        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertSame(
            'graduated',
            \App\Models\Enrollment::where('student_id', $student->id)->where('academic_year_id', $this->year->id)->value('status'),
        );
    }

    public function test_a_newly_imported_year_derives_its_dates_from_its_label(): void
    {
        $csv = "fee_type_code,unit_code,tingkat,academic_year,amount,due_day,late_fee_amount\n"
            ."spp,SD-13,,2030/2031,650000,10,0\n";

        $this->actingAs($this->admin)
            ->post('/api/admin/import/fee-rates', ['file' => $this->csvUpload($csv, 'tarif.csv')])
            ->assertOk();

        // Hardcoded 2027 dates made "2030/2031" start in 2027 - poisoning
        // promotion's chronological guard and every latest('starts_on')
        // fallback (audit T48-c).
        $year = AcademicYear::where('year', '2030/2031')->first();
        $this->assertNotNull($year);
        $this->assertSame('2030-07-01', $year->starts_on->toDateString());
        $this->assertSame('2031-06-30', $year->ends_on->toDateString());
    }

    public function test_grades_refuse_a_classroom_from_another_year_than_the_lit_term(): void
    {
        $guru = User::create([
            'name' => 'Guru Tahun Lama',
            'role' => 'guru',
            'school_unit_id' => $this->sdUnit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $classroom = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 1, 'name' => '1-A', 'is_active' => true,
        ]);
        $subject = \App\Models\Subject::create(['code' => 'mtk', 'name' => 'Matematika']);

        \App\Models\ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Siswa Tahun Lama', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);
        \App\Models\Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'classroom_id' => $classroom->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        // Rollover: next year active (the seeded 2027/2028), its semester
        // lit - but promotion has not moved this classroom anywhere yet.
        $nextYear = AcademicYear::where('year', '2027/2028')->firstOrFail();
        $nextYear->activate();
        \App\Models\Term::create([
            'academic_year_id' => $nextYear->id, 'name' => 'ganjil',
            'starts_on' => '2027-07-01', 'ends_on' => '2027-12-31',
        ])->activate();

        // The write used to succeed - filing old-year grades under the NEW
        // term, where the new year's rapor would show them (audit T48-b).
        $this->actingAs($guru)
            ->postJson("/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades", [
                'category' => 'tugas',
                'entries' => [['student_ulid' => $student->ulid, 'score' => 90]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'tahun ajaran'));

        $this->assertSame(0, \App\Models\Grade::count());
    }

    // --------------------------------------------------------------- T53

    public function test_a_deactivated_account_cannot_use_a_live_invitation(): void
    {
        $user = User::create([
            'name' => 'Wali Dinonaktifkan',
            'email' => 'off@yapinet.id',
            'role' => 'orangtua',
            'is_active' => false,
        ]);

        $token = \App\Models\AccountInvitation::generateToken();

        \App\Models\AccountInvitation::create([
            'user_id' => $user->id,
            'token_hash' => \App\Models\AccountInvitation::hashToken($token),
            'channel' => 'email',
            'sent_to' => 'baru@yapinet.id',
            'purpose' => 'reset',
            'expires_at' => now()->addDays(7),
        ]);

        // Neither the preview nor the activation may run for a disabled
        // account - the link outlived the deactivation by up to 7 days
        // (audit T53-a).
        $this->getJson("/api/invitations/{$token}")->assertStatus(403);
        $this->postJson("/api/invitations/{$token}/activate")->assertStatus(403);
        $this->assertNotNull(\App\Models\AccountInvitation::where('user_id', $user->id)->first()->fresh()->used_at === null ? 'unused' : null);
    }

    public function test_deactivating_a_user_consumes_their_live_invitations(): void
    {
        $user = User::create([
            'name' => 'Wali Dimatikan',
            'email' => 'off2@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        for ($i = 0; $i < 2; $i++) {
            \App\Models\AccountInvitation::create([
                'user_id' => $user->id,
                'token_hash' => \App\Models\AccountInvitation::hashToken(\App\Models\AccountInvitation::generateToken()),
                'channel' => 'email',
                'sent_to' => "x{$i}@yapinet.id",
                'purpose' => 'activation',
                'expires_at' => now()->addDays(7),
            ]);
        }

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->ulid}", ['is_active' => false])
            ->assertOk();

        $this->assertSame(
            0,
            \App\Models\AccountInvitation::where('user_id', $user->id)->whereNull('used_at')->count(),
            'semua undangan hidup harus ikut terkonsumsi saat akun dinonaktifkan',
        );
    }

    public function test_a_staff_contact_never_becomes_a_guardian_via_pmb(): void
    {
        // A teacher enrolling their own child through PMB (the common case):
        // the staff account must not be attached as the child's wali.
        $guru = User::create([
            'name' => 'Guru Punya Anak',
            'email' => 'guru.anak@yapinet.id',
            'role' => 'guru',
            'school_unit_id' => $this->sdUnit->id,
            'is_active' => true,
        ]);

        $event = \App\Models\IntegrationEvent::create([
            'source' => 'pmb',
            'event_type' => 'student.enrolled',
            'event_id' => 'evt-t53-'.uniqid(),
            'payload' => [
                'event' => 'student.enrolled',
                'occurred_at' => now()->toIso8601String(),
                'student' => [
                    'pmb_ulid' => '01JCT53STUDENT00000000001',
                    'no_pendaftaran' => 'PMB-2026-T53',
                    'nama_lengkap' => 'Anak Guru',
                    'jenis_kelamin' => 'P',
                    'tanggal_lahir' => '2019-05-01',
                    'unit_code' => 'SD-13',
                    'academic_year' => '2026/2027',
                ],
                'guardians' => [
                    [
                        'nama' => 'Guru Punya Anak',
                        'hubungan' => 'ibu',
                        'email' => 'guru.anak@yapinet.id',
                        'is_primary' => true,
                    ],
                ],
            ],
            'status' => 'received',
        ]);

        try {
            app(\App\Services\Handoff\PmbHandoffProcessor::class)->process($event);
            $this->fail('kecocokan kontak staf harus melempar agar event gagal terlihat');
        } catch (\RuntimeException $e) {
            // process() marks the event failed, then rethrows for the queue.
            $this->assertStringContainsString('akun staf', $e->getMessage());
        }

        // The event fails LOUDLY for manual pairing - never a silent staff-
        // as-guardian attachment (audit T53-b).
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertStringContainsString('akun staf', (string) $event->fresh()->error);
        $this->assertNull($guru->fresh()->guardian);
    }

    // --------------------------------------------------------------- T55

    public function test_a_failed_va_registration_fails_the_payment_instead_of_leaving_it_dangling(): void
    {
        $bill = $this->billedStudent();
        $guardian = $bill->student->guardians->first();

        $wali = User::create(['name' => 'Wali VA Gagal', 'role' => 'orangtua', 'is_active' => true]);
        $guardian->forceFill(['user_id' => $wali->id])->save();

        // Production's behaviour on a registration failure: the gateway
        // throws AFTER the Payment row committed.
        $this->app->bind(\App\Services\Payment\PaymentGateway::class, function () {
            return new class implements \App\Services\Payment\PaymentGateway
            {
                public function createInvoice(Payment $payment, \Illuminate\Support\Collection $bills, Guardian $payer): Payment
                {
                    throw new \RuntimeException('e-SPP unreachable in production');
                }
            };
        });

        try {
            app(CheckoutService::class)->start($wali, [$bill->ulid], 'virtual_account');
            $this->fail('kegagalan registrasi harus diteruskan');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('e-SPP unreachable', $e->getMessage());
        }

        // Not pending-forever: failed with a reason, basket released (audit
        // T55-a) - the row no longer dangles outside every poller query.
        $payment = Payment::sole();
        $this->assertSame('failed', $payment->status);
        $this->assertStringContainsString('Registrasi Virtual Account gagal', (string) $payment->rejection_reason);
        $this->assertSame('unpaid', $bill->fresh()->status);
    }

    public function test_the_receipt_period_label_uses_the_bills_own_period_month(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'tpl-receipt']);
        Queue::fake();

        // July's SPP, printed late in September: the receipt must say the
        // month the family was BILLED for, not the printing date (audit
        // T55-b).
        $bill = $this->billedStudent([
            'period_month' => 7,
            'issued_at' => now()->setMonth(9)->setDay(15),
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-SEP25-PER',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000PP', 'bank_name' => 'Bank Muamalat'],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        app(\App\Services\Billing\PaymentReceiptSender::class)->send($payment);

        Queue::assertPushed(\App\Jobs\SendQontakTemplateMessage::class, fn ($job) => $job->bodyValues[1] === 'Juli 2026');
    }

    public function test_an_identical_manual_bill_double_submit_is_refused(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa Tagihan Manual', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);

        $payload = [
            'student_ulid' => $student->ulid,
            'fee_type_ulid' => $this->spp->ulid,
            'description' => 'SPP tertunggak bulan Agustus',
            'amount' => 650000,
            'due_date' => now()->addDays(10)->toDateString(),
        ];

        $this->actingAs($this->admin)->postJson('/api/admin/bills/manual', $payload)->assertCreated();

        // The identical payload again (a double click, a retried request):
        // refused as a duplicate, not minted as a second real bill whose
        // checkout would supersede the first family VA (audit T55-d).
        $this->actingAs($this->admin)
            ->postJson('/api/admin/bills/manual', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'sama'));

        // A genuinely different bill still goes through.
        $changed = $payload;
        $changed['amount'] = 350000;

        $this->actingAs($this->admin)
            ->postJson('/api/admin/bills/manual', $changed)
            ->assertCreated();

        $this->assertSame(2, Bill::where('student_id', $student->id)->count());
    }

    // --------------------------------------------------------------- T49

    /** A student actively enrolled in the given classroom. */
    private function studentIn(\App\Models\Classroom $classroom, string $nama): Student
    {
        $student = Student::create([
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'school_unit_id' => $classroom->school_unit_id,
            'entry_year_id' => $classroom->academic_year_id,
            'status' => 'active',
        ]);

        \App\Models\Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $classroom->academic_year_id,
            'classroom_id' => $classroom->id,
            'status' => 'active',
            'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    public function test_promotion_respects_the_target_classrooms_capacity(): void
    {
        $source = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 1, 'name' => '1-A', 'is_active' => true,
        ]);
        $nextYear = AcademicYear::where('year', '2027/2028')->firstOrFail();
        $target = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $nextYear->id,
            'tingkat' => 2, 'name' => '2-A', 'is_active' => true, 'capacity' => 1,
        ]);

        $anak = $this->studentIn($source, 'Anak Pertama');
        $budi = $this->studentIn($source, 'Anak Kedua');

        $service = app(\App\Services\Academic\PromotionService::class);

        try {
            $service->promoteBatch($source, $nextYear, collect([
                ['student' => $anak, 'outcome' => 'promoted', 'target_classroom' => $target],
                ['student' => $budi, 'outcome' => 'promoted', 'target_classroom' => $target],
            ]), $this->admin);
            $this->fail('batch melebihi kapasitas harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah penuh', $e->getMessage());
        }

        // The whole batch rolled back - seat one never landed either.
        $this->assertSame(2, \App\Models\Enrollment::where('classroom_id', $source->id)->where('status', 'active')->count());
        $this->assertSame(0, \App\Models\Enrollment::where('classroom_id', $target->id)->count());
    }

    public function test_the_promotion_roster_flags_students_with_open_bills(): void
    {
        $source = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 1, 'name' => '1-B', 'is_active' => true,
        ]);

        $debitur = $this->studentIn($source, 'Siswa Debitur');
        $lunas = $this->studentIn($source, 'Siswa Lunas');

        Bill::create([
            'bill_number' => 'SPP/2026/00001',
            'student_id' => $debitur->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:t49:1',
            'description' => 'SPP',
            'subtotal' => 100, 'total_amount' => 100, 'remaining_amount' => 100,
            'status' => 'unpaid', 'due_date' => now()->addDays(7), 'issued_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/classrooms/{$source->ulid}/promotion-roster")
            ->assertOk();

        $byName = collect($response->json('students'))->keyBy('nama_lengkap');

        $this->assertTrue($byName['Siswa Debitur']['has_open_bills']);
        $this->assertFalse($byName['Siswa Lunas']['has_open_bills']);
    }

    public function test_a_promotion_batch_can_be_undone(): void
    {
        $source = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 1, 'name' => '1-C', 'is_active' => true,
        ]);
        $nextYear = AcademicYear::where('year', '2027/2028')->firstOrFail();
        $nextYear->activate();
        $target = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $nextYear->id,
            'tingkat' => 2, 'name' => '2-C', 'is_active' => true,
        ]);

        $student = $this->studentIn($source, 'Siswa Diundo');

        app(\App\Services\Academic\PromotionService::class)->promoteBatch(
            $source,
            $nextYear,
            collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]),
            $this->admin,
        );

        $this->assertSame(
            'promoted',
            \App\Models\Enrollment::where('student_id', $student->id)->where('classroom_id', $source->id)->value('status'),
        );

        $result = app(\App\Services\Academic\PromotionService::class)->undoBatch($source, $this->admin);

        $this->assertSame(1, $result['undone']);
        $this->assertSame([], $result['skipped']);

        // Back in the source world: enrollment reopened, new-year row gone,
        // student active again (audit T49-a).
        $this->assertSame('active', \App\Models\Enrollment::where('student_id', $student->id)->where('classroom_id', $source->id)->value('status'));
        $this->assertSame(0, \App\Models\Enrollment::where('student_id', $student->id)->where('classroom_id', $target->id)->count());
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_undo_refuses_when_the_target_year_already_has_bills(): void
    {
        $source = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 1, 'name' => '1-D', 'is_active' => true,
        ]);
        $nextYear = AcademicYear::where('year', '2027/2028')->firstOrFail();
        $nextYear->activate();
        $target = \App\Models\Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $nextYear->id,
            'tingkat' => 2, 'name' => '2-D', 'is_active' => true,
        ]);

        $student = $this->studentIn($source, 'Siswa Berbayar');

        app(\App\Services\Academic\PromotionService::class)->promoteBatch(
            $source,
            $nextYear,
            collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]),
            $this->admin,
        );

        // The new year already billed them (SPP ran) - undoing would orphan
        // real money, so it refuses instead (audit T49-a).
        Bill::create([
            'bill_number' => 'SPP/2027/00001',
            'student_id' => $student->id,
            'academic_year_id' => $nextYear->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:t49:2',
            'description' => 'SPP 2027',
            'subtotal' => 100, 'total_amount' => 100, 'remaining_amount' => 100,
            'status' => 'unpaid', 'due_date' => now()->addDays(7), 'issued_at' => now(),
        ]);

        try {
            app(\App\Services\Academic\PromotionService::class)->undoBatch($source, $this->admin);
            $this->fail('undo dengan tagihan hidup di tahun tujuan harus ditolak');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nilai atau tagihan', $e->getMessage());
        }

        // Nothing rolled back - the promotion still stands.
        $this->assertSame('active', \App\Models\Enrollment::where('student_id', $student->id)->where('classroom_id', $target->id)->value('status'));
    }

    // --------------------------------------------------------------- T39

    public function test_two_stale_snapshots_cannot_both_settle_one_payment(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'tpl-receipt']);
        Queue::fake();

        $bill = $this->billedStudent();

        $payment = Payment::create([
            'payment_number' => 'PAY-SEP25-RACE',
            'payer_guardian_id' => $bill->student->guardians->first()->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000RC', 'bank_name' => 'Bank Muamalat'],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        // The webhook and the poller each hold their own snapshot of the
        // still-'processing' row (audit T39-a) - exactly how double receipts
        // used to happen.
        $webhookSnapshot = Payment::find($payment->id);
        $pollerSnapshot = Payment::find($payment->id);

        app(PaymentAllocator::class)->settle($webhookSnapshot, 'EXT-WEBHOOK');
        app(PaymentAllocator::class)->settle($pollerSnapshot, 'EXT-POLLER');

        $fresh = $payment->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('EXT-WEBHOOK', $fresh->external_transaction_id, 'pemenang klaim menulis id-nya');

        // One receipt, one booking - the loser changed nothing.
        $this->assertSame(1, NotificationLog::where('template', 'receipt_spp_school')->count());
        $this->assertSame(700000.0, (float) $bill->fresh()->paid_amount);
    }

    public function test_rechecking_out_books_a_va_that_was_already_paid(): void
    {
        $bill = $this->billedStudent();
        $guardian = $bill->student->guardians->first();

        $wali = User::create(['name' => 'Wali Balapan Checkout', 'role' => 'orangtua', 'is_active' => true]);
        $guardian->forceFill(['user_id' => $wali->id])->save();

        // A live checkout whose VA was already paid at the bank - the bank
        // knows, our snapshot does not (audit T39-c).
        $pending = Payment::create([
            'payment_number' => 'PAY-SEP25-STALE',
            'payer_guardian_id' => $guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'metadata' => ['bill_ulids' => [$bill->ulid], 'bank_channel' => 'muamalat'],
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '80200126270000ST', 'bank_name' => 'Bank Muamalat'],
        ]);
        app(PaymentAllocator::class)->allocate($pending, [$bill->id => 700000]);

        $this->app->bind(BillingApiClient::class, function () {
            return new class extends BillingApiClient
            {
                public function __construct() {}

                public function getByVaNumber(string $vaNumber): array
                {
                    return ['sisa' => 0];
                }

                public function createBilling(array $main, array $bmi, array $bsm): array
                {
                    return ['uuid' => 'new-uuid', 'status' => 'success'];
                }
            };
        });

        try {
            app(CheckoutService::class)->start($wali, [$bill->ulid], 'virtual_account');
            $this->fail('checkout di atas VA yang sudah dibayar harus digagalkan');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah dibayar', $e->getMessage());
        }

        // The money was BOOKED under the payment that earned it, the bill
        // closed - instead of vanishing into manual reconciliation.
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame('paid', $bill->fresh()->status);
    }

    // ------------------------------------------------------------- §6a-3

    public function test_a_holiday_on_a_day_no_unit_operates_on_is_flagged(): void
    {
        // Every weekday EXCEPT today's, so "today" is a dead day.
        $today = (int) now('Asia/Jakarta')->dayOfWeekIso;

        \App\Models\DailyAttendanceSetting::create([
            'school_unit_id' => $this->sdUnit->id,
            'enabled' => true,
            'days' => collect([1, 2, 3, 4, 5])->reject(fn ($d) => $d === $today)->values()->all(),
            'masuk_opens_at' => '06:30',
            'masuk_closes_at' => '08:00',
            'pulang_enabled' => false,
            'intake_mode' => 'wali_kelas',
        ]);

        $dead = $this->actingAs($this->admin)->postJson('/api/admin/holidays', [
            'date' => now('Asia/Jakarta')->toDateString(),
            'label' => 'Uji Hari Mati',
        ]);

        // Soft by design (harmless - runsOn() gates sessions anyway), but
        // the server now says it out loud instead of leaving the calendar
        // to hint at it (audit §6a-3).
        $dead->assertCreated();
        $this->assertNotNull($dead->json('warning'));
        $this->assertStringContainsString('tidak berdampak', (string) $dead->json('warning'));

        // Next week's same weekday is still dead; tomorrow (an operating
        // day for this fixture when today isn't Saturday/Sunday) is not
        // flagged. A holiday on an operating day is the feature working -
        // no warning there.
        $tomorrow = now('Asia/Jakarta')->addDay();
        $operating = $this->actingAs($this->admin)->postJson('/api/admin/holidays', [
            'date' => $tomorrow->toDateString(),
            'label' => 'Uji Hari Operasional',
        ]);

        if (in_array((int) $tomorrow->dayOfWeekIso, [1, 2, 3, 4, 5], true)) {
            $operating->assertCreated();
            $this->assertNull($operating->json('warning'));
        } else {
            $operating->assertCreated();
            $this->assertNotNull($operating->json('warning'));
        }
    }
}
