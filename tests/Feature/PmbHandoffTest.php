<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Fase 1 contract end to end: PMB reports a settled uang pangkal, and a
 * guardian ends up able to sign in.
 */
class PmbHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only puts a session on requests that come from the frontend
        // origin, and the SPA's session is what carries login between calls -
        // so the suite sends the same Origin a browser would, exercising the
        // real stateful path rather than a bearer-token shortcut.
        $this->withHeader('Origin', config('app.frontend_url'));

        SchoolUnit::create([
            'code' => 'SD-SAKINAH',
            'label' => 'SD Sakinah',
            'jenjang_group' => 'sd',
        ]);

        // A fake gateway, so the assertions can read what the guardian would
        // actually receive instead of just that "something was sent".
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

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'student.enrolled',
            'event_id' => '01JCEVENT0000000000000001',
            'occurred_at' => now()->toIso8601String(),
            'student' => [
                'pmb_ulid' => '01JCSTUDENT000000000000001',
                'no_pendaftaran' => 'PMB-2026-00214',
                'nama_lengkap' => 'Aisyah Nur Ramadhani',
                'nama_panggilan' => 'Aisyah',
                'jenis_kelamin' => 'P',
                'tanggal_lahir' => '2014-03-11',
                'nisn' => '0123456789',
                'nik' => '3273010101140001',
                'unit_code' => 'SD-SAKINAH',
                'academic_year' => '2026/2027',
            ],
            'guardians' => [
                [
                    'nama' => 'Budi Ramadhani',
                    'hubungan' => 'ayah',
                    'email' => 'budi@example.com',
                    'no_hp' => '081234567890',
                    'is_primary' => true,
                ],
                [
                    'nama' => 'Siti Aminah',
                    'hubungan' => 'ibu',
                    'no_hp' => '081298765432',
                    'is_primary' => false,
                ],
            ],
        ], $overrides);
    }

    private function postHandoff(array $payload)
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, config('services.pmb.handoff_secret'));

        return $this->call(
            'POST',
            '/api/webhooks/pmb/students',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PMB_SIGNATURE' => $signature,
            ],
            content: $body
        );
    }

    public function test_it_rejects_an_unsigned_handoff(): void
    {
        $this->postJson('/api/webhooks/pmb/students', $this->payload())
            ->assertStatus(401);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_it_rejects_a_handoff_signed_with_the_wrong_secret(): void
    {
        $body = json_encode($this->payload());

        $this->call(
            'POST',
            '/api/webhooks/pmb/students',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PMB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'),
            ],
            content: $body
        )->assertStatus(401);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_a_settled_uang_pangkal_creates_the_student_guardians_and_an_invitation(): void
    {
        $this->postHandoff($this->payload())
            ->assertStatus(202)
            ->assertJson(['status' => 'queued']);

        $student = Student::firstWhere('pmb_student_ulid', '01JCSTUDENT000000000000001');

        $this->assertNotNull($student);
        $this->assertSame('Aisyah Nur Ramadhani', $student->nama_lengkap);
        // PMB only sends this event once the bill is settled, so the student is
        // active immediately and will be picked up by the Fase 2 SPP generator.
        $this->assertSame('active', $student->status);
        $this->assertSame('SD-SAKINAH', $student->schoolUnit->code);

        // NIK is readable through the model but never stored in the clear.
        $this->assertSame('3273010101140001', $student->nik);
        $this->assertNotSame('3273010101140001', $student->getRawAttributeValue('nik'));
        $this->assertNotNull($student->getRawAttributeValue('nik_hash'));

        $this->assertCount(2, $student->guardians);

        $father = $student->guardians->firstWhere('hubungan', 'ayah');
        $this->assertTrue((bool) $father->pivot->is_billing_contact);
        $this->assertSame('081234567890', $father->no_hp);

        // Only the primary guardian gets a login; the mother is a contact.
        $user = $father->user;
        $this->assertNotNull($user);
        $this->assertSame('orangtua', $user->role);
        $this->assertNull($user->password);
        $this->assertNull($student->guardians->firstWhere('hubungan', 'ibu')->user_id);

        $invitation = AccountInvitation::where('user_id', $user->id)->first();
        $this->assertNotNull($invitation);
        $this->assertSame('email', $invitation->channel);
        $this->assertTrue($invitation->isUsable());

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('budi@example.com', $this->sentMail[0]['to']);
        $this->assertSame('school_account_invite', $this->sentMail[0]['template']);
        $this->assertStringContainsString('/aktivasi?token=', $this->sentMail[0]['data']['activation_url']);

        $this->assertSame('processed', IntegrationEvent::first()->status);
    }

    public function test_a_redelivered_event_changes_nothing(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $this->postHandoff($this->payload())
            ->assertStatus(202)
            ->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_invitations', 1);
        // The decisive one: a retry must not mail a second invitation.
        $this->assertCount(1, $this->sentMail);
    }

    public function test_a_second_child_lands_on_the_same_parent_login(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $this->postHandoff($this->payload([
            'event_id' => '01JCEVENT0000000000000002',
            'student' => [
                'pmb_ulid' => '01JCSTUDENT000000000000002',
                'nama_lengkap' => 'Fathan Ramadhani',
                'nama_panggilan' => 'Fathan',
                'jenis_kelamin' => 'L',
                'nisn' => '0123456788',
                'nik' => '3273010101140002',
            ],
        ]))->assertStatus(202);

        $this->assertDatabaseCount('students', 2);

        // One father, one login, two children - the whole reason guardians and
        // students are many-to-many.
        $this->assertSame(1, Guardian::where('hubungan', 'ayah')->count());
        $this->assertSame(1, User::where('role', 'orangtua')->count());

        $father = Guardian::firstWhere('hubungan', 'ayah');
        $this->assertCount(2, $father->students);
    }

    public function test_an_unknown_unit_fails_the_event_instead_of_inventing_one(): void
    {
        $this->postHandoff($this->payload(['student' => ['unit_code' => 'SD-TIDAK-ADA']]));

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('school_units', 1);

        $event = IntegrationEvent::first();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('Unit tidak dikenal', $event->error);
    }

    public function test_the_guardian_activates_the_account_and_is_signed_in(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $token = $this->tokenFromLastMail();

        $this->getJson("/api/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('name', 'Budi Ramadhani')
            ->assertJsonPath('students.0.nama_lengkap', 'Aisyah Nur Ramadhani');

        $this->postJson("/api/invitations/{$token}/activate")
            ->assertOk()
            ->assertJsonPath('user.role', 'orangtua');

        $user = User::firstWhere('email', 'budi@example.com');
        $this->assertNotNull($user->activated_at);
        // No password is ever set for a guardian: holding the link proves they
        // control the address, and every later sign-in uses a one-time code.
        $this->assertNull($user->password);
        $this->assertAuthenticatedAs($user);

        // Single use: the same link must not work twice.
        $this->postJson("/api/invitations/{$token}/activate")->assertStatus(404);
    }

    public function test_an_unactivated_account_cannot_log_in(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $this->postJson('/api/auth/login', [
            'identifier' => 'budi@example.com',
            'password' => 'apa-saja',
        ])->assertStatus(422);
    }

    public function test_a_guardian_logs_in_and_sees_only_their_own_children(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $token = $this->tokenFromLastMail();
        $this->postJson("/api/invitations/{$token}/activate", [
            'password' => 'rahasia-kuat-2026',
            'password_confirmation' => 'rahasia-kuat-2026',
        ])->assertOk();

        // A second family, whose child must not appear in the first one's list.
        $this->postHandoff($this->payload([
            'event_id' => '01JCEVENT0000000000000003',
            'student' => [
                'pmb_ulid' => '01JCSTUDENT000000000000003',
                'nama_lengkap' => 'Zaki Alfarizi',
                'nama_panggilan' => 'Zaki',
                'jenis_kelamin' => 'L',
                'nisn' => '0123456787',
                'nik' => '3273010101140003',
            ],
            'guardians' => [
                ['nama' => 'Ahmad Fauzi', 'hubungan' => 'ayah', 'email' => 'ahmad@example.com', 'no_hp' => '081200000001', 'is_primary' => true],
                ['nama' => 'Dewi Lestari', 'hubungan' => 'ibu', 'no_hp' => '081200000002', 'is_primary' => false],
            ],
        ]))->assertStatus(202);

        $this->getJson('/api/wali/students')
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.nama_lengkap', 'Aisyah Nur Ramadhani');
    }

    private function tokenFromLastMail(): string
    {
        $url = $this->sentMail[array_key_last($this->sentMail)]['data']['activation_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
