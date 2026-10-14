<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\QontakWhatsAppGateway;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Security\FieldEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The lost-access lane: a parent or teacher who lost BOTH their email and
 * their phone has no OTP left to receive. A central admin sends a reset
 * invitation to a NEW contact collected in person; opening the link writes
 * that contact onto the account (and its guardian/staff mirror), and the
 * next OTP login works through it.
 */
class UserResetAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

    /** @var list<array{to: string, template: string, data: array<string, mixed>}> */
    private array $sentMail = [];

    private User $admin;

    private User $wali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));
        RateLimiter::clear('otp:ip:127.0.0.1');

        $this->app->bind(MailGateway::class, fn () => new class($this->sentMail) implements MailGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                $this->sent[] = compact('to', 'template', 'data');

                return NotificationResult::ok();
            }
        });

        $this->app->bind(WhatsAppGateway::class, fn () => new class($this->sentWhatsApp) implements WhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendMessage(string $phone, string $message): NotificationResult
            {
                $this->sent[] = compact('phone', 'message');

                return NotificationResult::ok();
            }
        });

        // Login OTPs over WhatsApp go through Qontak's official line, not
        // Sendago - recorded into the same list so the tests below read "a
        // message reached this phone" regardless of which gateway carried it.
        $this->app->bind(QontakWhatsAppGateway::class, fn () => new class($this->sentWhatsApp) extends QontakWhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendOtp(string $phone, string $code): NotificationResult
            {
                $this->sent[] = ['phone' => $phone, 'message' => "Kode masuk: {$code}"];

                return NotificationResult::ok();
            }
        });

        SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13', 'jenjang_group' => 'sd']);

        $this->admin = User::create([
            'name' => 'Pusat', 'email' => 'admin@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->wali = User::create([
            'name' => 'Wali Terkunci', 'email' => 'lama@example.com', 'phone' => '081200000001', 'role' => 'orangtua',
            'is_active' => true, 'activated_at' => now(),
        ]);
        Guardian::create([
            'user_id' => $this->wali->id,
            'nama' => 'Wali Terkunci',
            'hubungan' => 'ayah',
            'no_hp' => '081200000001',
        ]);
    }

    public function test_a_central_admin_sends_a_reset_invitation_to_a_new_email(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'Wali.Baru@Example.com'])
            ->assertCreated()
            ->assertJsonPath('invitation.purpose', 'reset')
            ->assertJsonPath('invitation.channel', 'email')
            ->assertJsonPath('invitation.sent_to', 'wali.baru@example.com');

        $invitation = AccountInvitation::where('purpose', 'reset')->first();
        $this->assertEquals($this->admin->id, $invitation->created_by);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'email',
            'template' => 'school_account_reset',
            'notifiable_type' => AccountInvitation::class,
            'notifiable_id' => $invitation->id,
            'status' => 'sent',
        ]);
        $this->assertSame('wali.baru@example.com', end($this->sentMail)['to']);
        $this->assertSame([], $this->sentWhatsApp);
    }

    /** Email only (2026-10-14): a phone number is refused, never sent over WhatsApp. */
    public function test_a_phone_number_is_refused_as_a_reset_contact(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '081299000111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact']);

        $this->assertSame(0, AccountInvitation::where('purpose', 'reset')->count());
        $this->assertSame([], $this->sentWhatsApp);
    }

    public function test_a_second_reset_supersedes_the_first_but_never_an_activation_link(): void
    {
        $activation = AccountInvitation::create([
            'user_id' => $this->wali->id,
            'token_hash' => AccountInvitation::hashToken(AccountInvitation::generateToken()),
            'channel' => 'email',
            'sent_to' => 'lama@example.com',
            'purpose' => 'activation',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'satu@example.com'])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'dua@example.com'])
            ->assertCreated();

        $resets = AccountInvitation::where('purpose', 'reset')->orderBy('id')->get();
        $this->assertSame(2, $resets->count());
        $this->assertNotNull($resets[0]->used_at, 'the first reset link is consumed by the second');
        $this->assertNull($resets[1]->used_at);
        // Purposes live separate lives: the activation link stays live.
        $this->assertNull($activation->fresh()->used_at);
    }

    public function test_the_lane_is_refused_for_unit_admins_admin_targets_and_taken_contacts(): void
    {
        $unitAdmin = User::create([
            'name' => 'Unit', 'email' => 'unit@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => SchoolUnit::first()->id, 'is_active' => true, 'activated_at' => now(),
        ]);
        $otherAdmin = User::create([
            'name' => 'Korban', 'email' => 'korban@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($unitAdmin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'baru@example.com'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$otherAdmin->ulid}/reset-access", ['contact' => 'baru@example.com'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'korban@yapinet.id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kontak ini sudah dipakai akun lain - tidak bisa menjadi kontak akun ini.');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'bukan-kontak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact']);

        $this->assertSame(0, AccountInvitation::where('purpose', 'reset')->count());
    }

    public function test_opening_the_reset_link_writes_the_new_email_and_login_follows_it(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'baru@example.com'])
            ->assertCreated();

        // The plain token lives only inside the delivered email.
        $token = (string) str(end($this->sentMail)['data']['activation_url'])->after('token=');
        $this->assertNotSame('', $token);

        $this->postJson("/api/invitations/{$token}/activate", [])
            ->assertOk()
            ->assertJsonPath('user.role', 'orangtua');

        $this->assertSame('baru@example.com', $this->wali->fresh()->email);
        $this->assertSame('baru@example.com', Guardian::where('user_id', $this->wali->id)->first()->email);

        // The dead old email no longer resolves - the request endpoint
        // answers the same either way (anti-enumeration); the proof is that
        // no code was mailed for it.
        $before = count($this->sentMail);
        $this->postJson('/api/auth/otp/request', ['identifier' => 'lama@example.com'])->assertOk();
        $this->assertCount($before, $this->sentMail);

        // The new one receives a code and logs the wali in.
        $this->postJson('/api/auth/otp/request', ['identifier' => 'baru@example.com'])->assertOk();
        $this->assertCount($before + 1, $this->sentMail);
        $code = (string) end($this->sentMail)['data']['code'];

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'baru@example.com', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.name', 'Wali Terkunci');
    }

    public function test_a_plain_activation_link_still_writes_no_contact(): void
    {
        $before = [
            'phone' => $this->wali->getRawAttributeValue('phone'),
            'email' => $this->wali->email,
        ];

        // Hand-minted activation invitation through the same sender the PMB
        // handoff uses, delivered to the email already on file.
        app(\App\Services\Handoff\AccountInvitationSender::class)
            ->send($this->wali, ['student_name' => 'Ananda', 'nama_panggilan' => 'Ananda', 'unit_label' => 'SD', 'academic_year' => '2026/2027']);

        $token = (string) str(end($this->sentMail)['data']['activation_url'])->after('token=');
        $this->postJson("/api/invitations/{$token}/activate", [])->assertOk();

        $this->assertSame($before['phone'], $this->wali->fresh()->getRawAttributeValue('phone'));
        $this->assertSame($before['email'], $this->wali->fresh()->email);
    }

    /** An account with no email gets no invitation - and no WhatsApp attempt or red row either. */
    public function test_an_account_without_email_is_not_invited_over_whatsapp(): void
    {
        $noEmail = User::create(['name' => 'Ayah Tanpa Email', 'phone' => '081277700001', 'role' => 'orangtua', 'is_active' => true]);

        $result = app(\App\Services\Handoff\AccountInvitationSender::class)
            ->send($noEmail, ['student_name' => 'Ananda', 'nama_panggilan' => 'Ananda', 'unit_label' => 'SD', 'academic_year' => '2026/2027']);

        $this->assertFalse($result->success);
        $this->assertSame(0, AccountInvitation::where('user_id', $noEmail->id)->count());
        $this->assertSame([], $this->sentWhatsApp);
        $this->assertDatabaseMissing('notification_logs', ['channel' => 'whatsapp']);
    }
}
