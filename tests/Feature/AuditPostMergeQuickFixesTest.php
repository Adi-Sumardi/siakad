<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AccountInvitation;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\LoginOtp;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentAllocator;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audit pasca-merge 2026-09-24, quick wins T38 + T52 (lihat
 * AUDIT-PASCA-MERGE-2026-09-24.md §B2/B3/B4/B8/H1 dan §F4/F5):
 * safety-net VA limit(0), settle() merge gateway_response, guard PMB
 * substring, rupiah utuh, dan konsumsi atomik + normalisasi kontak.
 */
class AuditPostMergeQuickFixesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sdUnit;

    private AcademicYear $year;

    private User $admin;

    private array $sentMail = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->sdUnit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        // Captures the OTP code the mail would deliver, so verify() can be
        // exercised against the real credential.
        $this->app->bind(MailGateway::class, fn () => new class($this->sentMail) implements MailGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                $this->sent[] = compact('to', 'template', 'data');

                return NotificationResult::ok();
            }
        });
    }

    // --------------------------------------------------------------- T38-a

    public function test_the_superseded_va_safety_net_runs_without_an_explicit_limit(): void
    {
        $looked = [];

        $this->app->bind(BillingApiClient::class, function () use (&$looked) {
            return new class($looked) extends BillingApiClient
            {
                public function __construct(private array &$looked) {}

                public function getByVaNumber(string $vaNumber): array
                {
                    $this->looked[] = $vaNumber;

                    return ['sisa' => 0];
                }
            };
        });

        // One pending row keeps the command past its empty-queue early return;
        // the failed row below is the one the safety net exists for.
        Payment::create([
            'payment_number' => 'PAY-SNET-P',
            'amount' => 100000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-SNET-P'],
        ]);

        Payment::create([
            'payment_number' => 'PAY-SNET-F',
            'amount' => 100000,
            'method' => 'virtual_account',
            'status' => 'failed',
            'failed_at' => now()->subDay(),
            'expires_at' => now()->addDays(2),
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => 'VA-SNET-F'],
        ]);

        // Default --limit=0 used to compile the superseded query to LIMIT 0:
        // the safety net never even asked the bank about the abandoned VA.
        $this->artisan('payments:poll-billing-va');

        $this->assertContains('VA-SNET-F', $looked, 'VA tersupersedi harus tetap diperiksa bank tanpa --limit eksplisit');
    }

    // --------------------------------------------------------------- T38-b

    public function test_settle_merges_gateway_response_instead_of_replacing_it(): void
    {
        $payment = Payment::create([
            'payment_number' => 'PAY-MERGE-1',
            'amount' => 250000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '8020012627000123',
                'bank_name' => 'Bank Muamalat',
            ],
        ]);

        app(PaymentAllocator::class)->settle($payment, 'EXT-1', [
            'sisa' => 0,
            'settled_via' => 'billing_api_poller',
        ]);

        $fresh = $payment->fresh();

        $this->assertSame('completed', $fresh->status);
        $this->assertSame('8020012627000123', $fresh->gateway_response['va_number'], 'va_number tidak boleh hilang saat settle menulis payload verifikasi');
        $this->assertSame('Bank Muamalat', $fresh->gateway_response['bank_name']);
        $this->assertSame(0, $fresh->gateway_response['sisa']);
        $this->assertSame('billing_api_poller', $fresh->gateway_response['settled_via']);
        // The receipt surfaces read the VA, not the internal number.
        $this->assertSame('8020012627000123', $fresh->referenceNumber());
    }

    // --------------------------------------------------------------- T38-c

    public function test_pmb_owned_fee_fragments_never_resolve_a_prefix(): void
    {
        // Every admin-invented spelling of PMB's fee families must be
        // refused - the old exact-match list let these straight through into
        // PMB's live VA ranges.
        $this->assertNull(BillingApiClient::resolvePrefix('uang-pangkal'));
        $this->assertNull(BillingApiClient::resolvePrefix('biaya_pendaftaran'));
        $this->assertNull(BillingApiClient::resolvePrefix('formulir'));
        $this->assertNull(BillingApiClient::resolvePrefix('Formulir-Pendaftaran-2027'));
        $this->assertNull(BillingApiClient::resolvePrefix('registration_fee'));

        // Siakad's own families are untouched.
        $this->assertSame('802001', BillingApiClient::resolvePrefix('spp'));
        $this->assertSame('802003', BillingApiClient::resolvePrefix('jamiyyah'));
        // 7895, not 3656: BSI splits by institution code (school decision
        // 2026-09-24) - 3656 is uang pangkal only, SPP lives under 7895.
        $this->assertSame('789501', BillingApiClient::resolvePrefix('spp', null, 'bsi'));
    }

    // --------------------------------------------------------------- T38-d

    public function test_checkout_rounds_charges_to_whole_rupiah(): void
    {
        // phpunit.xml blanks the e-SPP credentials (so no suite run can ever
        // talk to a real gateway), and a credential-less client throws before
        // issuing any HTTP at all - which lands in the non-production
        // "simulated VA" fallback. Provision dummy values so the registration
        // actually travels through the faked transport below.
        config([
            'services.billing_api.base_url' => 'http://espp.test',
            'services.billing_api.client_id' => 'cid',
            'services.billing_api.client_secret' => 'secret',
            'services.billing_api.username' => 'user',
            'services.billing_api.password' => 'pass',
        ]);

        Http::fake([
            '*/api/login' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/api/billing' => Http::response(['uuid' => 'espp-uuid-1']),
        ]);

        [$user, $bill] = $this->waliWithOpenBill(250000.40);

        $payment = app(CheckoutService::class)->start($user, [$bill->ulid], 'virtual_account', [
            $bill->ulid => '150000.40',
        ]);

        // The charge, the payment and the registration all carry one whole-
        // rupiah figure - a cents-bearing amount permanently tripped the
        // webhook's "Amount mismatch" and deferred settlement to the poller.
        $this->assertSame(150000.0, (float) $payment->amount);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/billing')) {
                return false;
            }

            // The client sends the payload as form params; decode whichever
            // way the body actually travelled.
            $decoded = json_decode((string) $request->body(), true);

            if (! is_array($decoded)) {
                parse_str((string) $request->body(), $decoded);
            }

            return (int) data_get($decoded, 'main_form.jumlah_tagihan') === 150000;
        });
    }

    // --------------------------------------------------------------- T52-1

    public function test_a_correct_otp_code_cannot_be_consumed_twice(): void
    {
        $user = User::create([
            'name' => 'Wali OTP',
            'email' => 'wali.otp@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $service = app(OtpService::class);
        $service->issue($user, 'wali.otp@yapinet.id');

        $code = $this->sentMail[0]['data']['code'];

        $this->assertSame($user->id, $service->verify('wali.otp@yapinet.id', $code)?->id);
        // Replay (or a concurrent second verify that lost the atomic consume)
        // must be refused, not handed a second session.
        $this->assertNull($service->verify('wali.otp@yapinet.id', $code));
    }

    // --------------------------------------------------------------- T52-2

    public function test_store_user_normalises_contacts_and_refuses_duplicate_phones(): void
    {
        $this->actingAs($this->admin);

        $first = $this->postJson('/api/admin/users', [
            'name' => 'Guru Satu',
            'phone' => '+62 812-3456-7890',
            'role' => 'orangtua',
        ])->assertStatus(201);

        // Login hashes the normalised form - storing the raw one created an
        // account its own contact could never sign in with. (Read through the
        // model, where the encrypted cast decrypts; the raw JSON carries
        // ciphertext.)
        $this->assertSame('081234567890', User::where('ulid', $first->json('user.ulid'))->first()->phone);

        // Any spelling of the same number now collides with the account above
        // instead of silently minting a second one.
        $this->postJson('/api/admin/users', [
            'name' => 'Guru Dua',
            'phone' => '6281234567890',
            'role' => 'orangtua',
        ])->assertStatus(422)->assertJsonPath('message', 'Nomor HP sudah dipakai akun lain - satu nomor hanya boleh satu akun.');

        // Email is case-folded the same way the login lane normalises it.
        $this->postJson('/api/admin/users', [
            'name' => 'Guru Tiga',
            'email' => 'Guru.Tiga@Yapinet.ID',
            'role' => 'orangtua',
        ])->assertStatus(201);

        $this->assertNotNull(User::firstWhere('email', 'guru.tiga@yapinet.id'));
    }

    public function test_updating_a_parent_email_keeps_the_guardian_mirror_in_step(): void
    {
        $this->actingAs($this->admin);

        $user = User::create([
            'name' => 'Wali Mirror',
            'email' => 'lama@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
            'email' => 'lama@yapinet.id',
        ]);

        $this->patchJson("/api/admin/users/{$user->ulid}", [
            'email' => 'Baru@yapinet.id',
        ])->assertOk();

        // A stale mirror made the next PMB handoff miss this guardian, mint a
        // second one, and die on the guardians.user_id unique index.
        $this->assertSame('baru@yapinet.id', $user->guardian->fresh()->email);
    }

    // --------------------------------------------------------------- T40

    public function test_the_wali_bill_detail_contract_carries_the_fields_the_screen_prints(): void
    {
        [$user, $bill] = $this->waliWithOpenBill(300000);
        $bill->forceFill(['allow_installment' => true])->save();

        $this->actingAs($user)
            ->getJson("/api/wali/bills/{$bill->ulid}")
            ->assertOk()
            ->assertJsonPath('bill.allow_installment', true)
            ->assertJsonPath('bill.issued_at', $bill->issued_at->toDateString())
            ->assertJsonPath('bill.academic_year.year', '2026/2027')
            ->assertJsonPath('bill.student.nis', '009999')
            ->assertJsonPath('bill.student.school_unit.label', 'SD Islam Al Azhar 13');
    }

    // --------------------------------------------------------------- T50

    public function test_opening_a_reset_link_revokes_every_old_way_in(): void
    {
        $user = User::create([
            'name' => 'Wali Direvoke',
            'email' => 'lama@yapinet.id',
            'phone' => '081200000099',
            'role' => 'orangtua',
            'is_active' => true,
        ]);
        $user->forceFill(['remember_token' => 'stolen-device-token'])->save();

        Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
            'no_hp' => '081200000099',
            'email' => 'lama@yapinet.id',
        ]);

        // An activation invitation still live in the OLD email's inbox...
        AccountInvitation::create([
            'user_id' => $user->id,
            'token_hash' => AccountInvitation::hashToken(AccountInvitation::generateToken()),
            'channel' => 'email',
            'sent_to' => 'lama@yapinet.id',
            'purpose' => 'activation',
            'expires_at' => now()->addDays(7),
        ]);

        // ...a code already issued to the OLD phone...
        LoginOtp::create([
            'user_id' => $user->id,
            'identifier' => '081200000099',
            'channel' => 'whatsapp',
            'code_hash' => LoginOtp::hashCode('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        // ...and a session the stolen device is holding open.
        DB::table('sessions')->insert([
            'id' => 'stolen-device-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $token = AccountInvitation::generateToken();

        AccountInvitation::create([
            'user_id' => $user->id,
            'token_hash' => AccountInvitation::hashToken($token),
            'channel' => 'whatsapp',
            'sent_to' => '081999000111',
            'purpose' => 'reset',
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson("/api/invitations/{$token}/activate")->assertOk();

        $fresh = $user->fresh();

        // The new contact is the ONLY way in now - the promise the message
        // always made and the code never delivered.
        $this->assertSame('081999000111', $fresh->phone);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->email_verified_at);
        // The re-login below issues a FRESH remember token - the stolen
        // device's cookie dies against the new one, which is the point.
        $this->assertNotSame('stolen-device-token', $fresh->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(
            0,
            LoginOtp::where('user_id', $user->id)->whereNull('consumed_at')->count(),
            'OTP yang sudah terbit ke kontak lama harus ikut terkonsumsi'
        );
        $this->assertSame(
            0,
            AccountInvitation::where('user_id', $user->id)->whereNull('used_at')->count(),
            'semua undangan harus ikut terkonsumsi'
        );

        // The mirrors follow: new phone on the guardian row, dead email gone.
        $guardian = $fresh->guardian->fresh();
        $this->assertSame('081999000111', $guardian->no_hp);
        $this->assertNull($guardian->email);
    }

    // --------------------------------------------------------------- T41

    public function test_a_cross_unit_promotion_moves_the_students_unit_with_them(): void
    {
        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp']);

        $source = Classroom::create([
            'school_unit_id' => $this->sdUnit->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 6, 'name' => '6-A', 'is_active' => true,
        ]);

        $nextYear = AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'],
        );

        $target = Classroom::create([
            'school_unit_id' => $smp->id, 'academic_year_id' => $nextYear->id,
            'tingkat' => 7, 'name' => '7-A', 'is_active' => true,
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Siswa Promosi', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $source->id,
            'academic_year_id' => $this->year->id, 'status' => 'active',
            'joined_on' => $this->year->starts_on,
        ]);

        app(\App\Services\Academic\PromotionService::class)->promoteBatch(
            $source,
            $nextYear,
            collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]),
            $this->admin,
        );

        // The enrollment moved - and the STUDENT ROW must follow it, or the
        // new unit's scoping, rosters, sweeps and fee rates all keep reading
        // the old unit (audit T41).
        $this->assertSame($smp->id, $student->fresh()->school_unit_id);
    }

    // -------------------------------------------------------------------

    /**
     * @return array{0: User, 1: Bill}
     */
    private function waliWithOpenBill(float $remaining): array
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa Rounded',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '009999',
        ]);

        $user = User::create([
            'name' => 'Wali Rounded',
            'email' => 'wali.rounded@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => $user->name,
            'hubungan' => 'wali',
        ]);
        $guardian->students()->attach($student->id, ['relationship' => 'wali', 'is_primary' => true, 'is_billing_contact' => true]);

        $feeType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $bill = Bill::create([
            'bill_number' => 'SPP/2026/09/00099',
            'dedup_key' => 'spp:2026:09:'.$student->id,
            'description' => 'SPP Bulan September 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $feeType->id,
            'subtotal' => $remaining,
            'total_amount' => $remaining,
            'remaining_amount' => $remaining,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        return [$user, $bill];
    }
}
