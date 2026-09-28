<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\IntegrationEvent;
use App\Models\LoginOtp;
use App\Models\User;
use App\Services\Security\FieldEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T51-b: the three PII columns left plaintext after the notification_logs
 * round. At rest they must carry ciphertext (a leaked dump hands over no
 * contact list, no handoff payload), while every reader keeps seeing the
 * original value through the model casts - and the one exact-value lookup
 * (OTP verify) rides the deterministic blind index instead.
 */
class SensitiveFieldEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_otp_identifier_is_ciphertext_with_a_usable_blind_index(): void
    {
        $user = User::create([
            'name' => 'Ibu Ani', 'email' => 'ani'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);

        $otp = LoginOtp::create([
            'user_id' => $user->id,
            'identifier' => 'ani@example.com',
            'channel' => 'email',
            'code_hash' => LoginOtp::hashCode('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $raw = DB::table('login_otps')->where('id', $otp->id)->first();

        $this->assertNotSame('ani@example.com', $raw->identifier, 'kolom identifier harus ciphertext');
        $this->assertSame('ani@example.com', $otp->fresh()->identifier, 'baca via model tetap plaintext');

        // The lookup verify() actually performs: deterministic, keyed, and
        // filled automatically on create.
        $this->assertSame(
            app(FieldEncrypter::class)->blindIndex('ani@example.com'),
            $raw->identifier_hash,
        );
        $this->assertNotNull(
            LoginOtp::where('identifier_hash', LoginOtp::blindIndexIdentifier('ani@example.com'))->where('id', $otp->id)->first(),
            'baris ditemukan lewat blind index',
        );
    }

    public function test_account_invitation_sent_to_is_ciphertext_at_rest(): void
    {
        $invitation = AccountInvitation::create([
            'user_id' => User::create([
                'name' => 'Pak Dedi', 'email' => 'dedi'.uniqid().'@example.com',
                'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
            ])->id,
            'token_hash' => AccountInvitation::hashToken(AccountInvitation::generateToken()),
            'channel' => 'email',
            'sent_to' => 'dedi@example.com',
            'purpose' => 'invite',
            'expires_at' => now()->addDays(7),
        ]);

        $raw = DB::table('account_invitations')->where('id', $invitation->id)->value('sent_to');

        $this->assertNotSame('dedi@example.com', $raw);
        $this->assertStringNotContainsString('dedi@example.com', $raw);
        $this->assertSame('dedi@example.com', $invitation->fresh()->sent_to);
    }

    public function test_integration_event_payload_is_ciphertext_at_rest_and_reads_back_as_array(): void
    {
        $event = IntegrationEvent::create([
            'source' => 'pmb',
            'event_type' => 'student.registered',
            'event_id' => 'evt-'.uniqid(),
            'payload' => ['student' => ['nama' => 'Adik Rahma', 'nik' => '3175010101010001']],
            'status' => 'pending',
        ]);

        $raw = (string) DB::table('integration_events')->where('id', $event->id)->value('payload');

        $this->assertStringNotContainsString('Adik Rahma', $raw, 'payload harus ciphertext, bukan JSON polos');
        $this->assertStringNotContainsString('3175010101010001', $raw);

        $read = $event->fresh()->payload;
        $this->assertSame('Adik Rahma', $read['student']['nama']);
        $this->assertSame('3175010101010001', $read['student']['nik']);
    }
}
