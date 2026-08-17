<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\SchoolUnit;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Guardians sign in with a one-time code and never hold a password. The channel
 * follows from what they typed: an email address gets an emailed code, a phone
 * number gets one over WhatsApp.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    /** @var list<array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

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
    }

    private function guardian(array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => 'Budi Ramadhani',
            'email' => 'budi@example.com',
            'role' => 'orangtua',
            'is_active' => true,
        ], $attributes));

        if (isset($attributes['phone'])) {
            $user->phone = $attributes['phone'];
        }

        $user->save();

        return $user;
    }

    /** The code as the guardian receives it - the API never returns it. */
    private function codeFromMail(): string
    {
        return $this->sentMail[array_key_last($this->sentMail)]['data']['code'];
    }

    public function test_an_email_identifier_gets_an_emailed_code(): void
    {
        $this->guardian();

        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])
            ->assertOk()
            ->assertJsonPath('channel', 'email')
            // Masked: enough to recognise, not enough to confirm to a stranger.
            ->assertJsonPath('identifier', 'bu**@example.com');

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('login_otp', $this->sentMail[0]['template']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->codeFromMail());

        // Only the hash is stored, so a database leak yields no live code.
        $otp = LoginOtp::first();
        $this->assertSame('email', $otp->channel);
        $this->assertNotSame($this->codeFromMail(), $otp->code_hash);
    }

    public function test_a_phone_identifier_gets_a_whatsapp_code(): void
    {
        $this->guardian(['email' => null, 'phone' => '081234567890']);

        $this->postJson('/api/auth/otp/request', ['identifier' => '081234567890'])
            ->assertOk()
            ->assertJsonPath('channel', 'whatsapp');

        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertEmpty($this->sentMail);
        $this->assertMatchesRegularExpression('/\d{6}/', $this->sentWhatsApp[0]['message']);
    }

    public function test_a_correct_code_signs_the_guardian_in_and_activates_the_account(): void
    {
        $user = $this->guardian();
        $this->assertFalse($user->hasActivated());

        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();

        $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'budi@example.com',
            'code' => $this->codeFromMail(),
        ])->assertOk()->assertJsonPath('user.role', 'orangtua');

        $this->assertAuthenticatedAs($user);

        // Proving control of the address is what activation ever checked, so a
        // first successful code counts as activation.
        $this->assertTrue($user->fresh()->hasActivated());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_code_works_only_once(): void
    {
        $this->guardian();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $code = $this->codeFromMail();

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $code])
            ->assertOk();

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->guardian();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();

        $wrong = $this->codeFromMail() === '000000' ? '111111' : '000000';

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, LoginOtp::first()->attempts);
        $this->assertGuest();
    }

    public function test_a_code_dies_after_five_wrong_guesses(): void
    {
        $this->guardian();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $code = $this->codeFromMail();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < LoginOtp::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $wrong])
                ->assertStatus(422);
        }

        // Six digits against a ten-minute window is guessable without this cap.
        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $code])
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->guardian();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $code = $this->codeFromMail();

        $this->travel(LoginOtp::TTL_MINUTES + 1)->minutes();

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_requesting_a_new_code_retires_the_previous_one(): void
    {
        $this->guardian();

        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $first = $this->codeFromMail();

        // Past the resend cooldown, which exists so this endpoint cannot be
        // used to bombard someone's phone.
        $this->travel(LoginOtp::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $second = $this->codeFromMail();

        $this->assertNotSame($first, $second);

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $first])
            ->assertStatus(422);
        $this->postJson('/api/auth/otp/verify', ['identifier' => 'budi@example.com', 'code' => $second])
            ->assertOk();
    }

    public function test_a_resend_inside_the_cooldown_is_refused(): void
    {
        $this->guardian();

        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])
            ->assertStatus(429)
            ->assertJsonStructure(['retry_after_seconds']);

        $this->assertCount(1, $this->sentMail);
    }

    public function test_an_unknown_address_looks_exactly_like_a_known_one(): void
    {
        // Otherwise this endpoint becomes a way to find out which families
        // attend the school.
        $this->postJson('/api/auth/otp/request', ['identifier' => 'orang.asing@example.com'])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        $this->assertEmpty($this->sentMail);
        $this->assertDatabaseCount('login_otps', 0);
    }

    public function test_a_deactivated_account_gets_no_code(): void
    {
        $this->guardian(['is_active' => false]);

        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])->assertOk();

        $this->assertEmpty($this->sentMail);
    }

    public function test_a_phone_number_matches_however_it_was_typed(): void
    {
        $this->guardian(['email' => null, 'phone' => '081234567890']);

        // Same person, three spellings, one account and one throttle bucket.
        $this->postJson('/api/auth/otp/request', ['identifier' => '+62 812-3456-7890'])->assertOk();

        $this->assertCount(1, $this->sentWhatsApp);
    }

    public function test_staff_sign_in_with_a_code_exactly_as_guardians_do(): void
    {
        $unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);

        $staff = User::create([
            'name' => 'Admin SD',
            'email' => 'admin.sd@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->postJson('/api/auth/otp/request', ['identifier' => 'admin.sd@yapinet.id'])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'admin.sd@yapinet.id',
            'code' => $this->codeFromMail(),
        ])->assertOk()->assertJsonPath('user.role', 'admin_unit');

        $this->assertAuthenticatedAs($staff);
    }

    public function test_there_is_no_password_endpoint_left_to_try(): void
    {
        $this->guardian();

        // Passwords are gone for everyone, so the route is gone too - not left
        // answering 422 for a credential nobody holds.
        $this->postJson('/api/auth/login', [
            'identifier' => 'budi@example.com',
            'password' => 'apa-saja',
        ])->assertStatus(404);
    }

    public function test_an_operator_can_issue_a_code_from_the_shell_when_gateways_are_down(): void
    {
        $this->guardian();

        // The only recovery path, and it needs shell access to the server -
        // deliberately a higher bar than a password reset link.
        $this->artisan('otp:issue', ['identifier' => 'budi@example.com'])
            ->assertSuccessful();

        $this->assertDatabaseCount('login_otps', 1);
        // Nothing was sent; the code was printed instead.
        $this->assertEmpty($this->sentMail);
    }
}
