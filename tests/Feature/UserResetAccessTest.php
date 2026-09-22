<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
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

    private User $admin;

    private User $wali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));
        RateLimiter::clear('otp:ip:127.0.0.1');

        $this->app->bind(MailGateway::class, fn () => new class implements MailGateway
        {
            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
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

        SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13', 'jenjang_group' => 'sd']);

        $this->admin = User::create([
            'name' => 'Pusat', 'email' => 'admin@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->wali = User::create([
            'name' => 'Wali Terkunci', 'phone' => '081200000001', 'role' => 'orangtua',
            'is_active' => true, 'activated_at' => now(),
        ]);
        Guardian::create([
            'user_id' => $this->wali->id,
            'nama' => 'Wali Terkunci',
            'hubungan' => 'ayah',
            'no_hp' => '081200000001',
        ]);
    }

    public function test_a_central_admin_sends_a_reset_invitation_to_a_new_contact(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '+6281299000111'])
            ->assertCreated()
            ->assertJsonPath('invitation.purpose', 'reset')
            ->assertJsonPath('invitation.channel', 'whatsapp')
            ->assertJsonPath('invitation.sent_to', '081299000111');

        $invitation = AccountInvitation::where('purpose', 'reset')->first();
        $this->assertEquals($this->admin->id, $invitation->created_by);
        $this->assertSame('081299000111', $invitation->sent_to);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'whatsapp',
            'template' => 'school_account_reset',
            'notifiable_type' => AccountInvitation::class,
            'notifiable_id' => $invitation->id,
            'status' => 'sent',
        ]);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame('081299000111', $this->sentWhatsApp[0]['phone']);
    }

    public function test_a_second_reset_supersedes_the_first_but_never_an_activation_link(): void
    {
        $activation = AccountInvitation::create([
            'user_id' => $this->wali->id,
            'token_hash' => AccountInvitation::hashToken(AccountInvitation::generateToken()),
            'channel' => 'whatsapp',
            'sent_to' => '081200000001',
            'purpose' => 'activation',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '081299000111'])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '081299000222'])
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
        $phoneOwner = User::create([
            'name' => 'Pemilik Nomor', 'phone' => '081299000333', 'role' => 'orangtua',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($unitAdmin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '081299000111'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$otherAdmin->ulid}/reset-access", ['contact' => '081299000111'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'korban@yapinet.id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kontak ini sudah dipakai akun lain - tidak bisa menjadi kontak akun ini.');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '081299000333'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => 'bukan-kontak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact']);

        $this->assertSame(0, AccountInvitation::where('purpose', 'reset')->count());
        $this->assertNotNull($phoneOwner);
    }

    public function test_opening_the_reset_link_writes_the_new_contact_and_login_follows_it(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->wali->ulid}/reset-access", ['contact' => '+62 812-9900-0111'])
            ->assertCreated();

        // The plain token lives only inside the delivered message.
        preg_match('#/aktivasi\?token=([A-Za-z0-9]+)#', $this->sentWhatsApp[0]['message'], $m);
        $token = $m[1] ?? '';
        $this->assertNotSame('', $token);

        $this->postJson("/api/invitations/{$token}/activate", [])
            ->assertOk()
            ->assertJsonPath('user.role', 'orangtua');

        // The new phone (normalized 08xx) is now the account's contact, with
        // the blind index OTP lookup reads, and the guardian mirror followed.
        $this->assertSame('081299000111', $this->wali->fresh()->phone);
        $this->assertSame(
            app(FieldEncrypter::class)->blindIndex('081299000111'),
            User::where('id', $this->wali->id)->value('phone_hash'),
        );
        $guardian = Guardian::where('user_id', $this->wali->id)->first();
        $this->assertSame('081299000111', $guardian->no_hp);

        // The dead old phone no longer resolves. The request endpoint answers
        // the same for known and unknown identifiers on purpose
        // (anti-enumeration) - the proof is that NO code was delivered for it.
        $messagesBefore = count($this->sentWhatsApp);
        $this->postJson('/api/auth/otp/request', ['identifier' => '081200000001'])->assertOk();
        $this->assertCount($messagesBefore, $this->sentWhatsApp);

        // The new one receives a code and logs the wali in.
        $this->postJson('/api/auth/otp/request', ['identifier' => '081299000111'])->assertOk();
        $this->assertCount($messagesBefore + 1, $this->sentWhatsApp);

        $code = $this->codeFromWhatsApp();
        $this->postJson('/api/auth/otp/verify', ['identifier' => '081299000111', 'code' => $code])
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
        // handoff uses, delivered to the contact already on file.
        app(\App\Services\Handoff\AccountInvitationSender::class)
            ->send($this->wali, ['student_name' => 'Ananda', 'nama_panggilan' => 'Ananda', 'unit_label' => 'SD', 'academic_year' => '2026/2027']);

        preg_match('#/aktivasi\?token=([A-Za-z0-9]+)#', $this->sentWhatsApp[0]['message'], $m);

        $this->postJson("/api/invitations/{$m[1]}/activate", [])->assertOk();

        $this->assertSame($before['phone'], $this->wali->fresh()->getRawAttributeValue('phone'));
        $this->assertSame($before['email'], $this->wali->fresh()->email);
    }

    /** The code as the guardian receives it - the API never returns it. */
    private function codeFromWhatsApp(): string
    {
        $messages = $this->sentWhatsApp;
        preg_match('/\b(\d{6})\b/', end($messages)['message'], $m);

        return $m[1];
    }
}
