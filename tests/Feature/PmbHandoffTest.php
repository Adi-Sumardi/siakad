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
use Illuminate\Database\QueryException;
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

    public function test_a_second_handoff_never_leaves_two_billing_contacts(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);
        $student = Student::first();

        // A redelivered/re-enrolled event for the same child whose PRIMARY
        // guardian differs (audit 2026-10-05): the old flow set a second
        // is_billing_contact=true and left every reader guessing which
        // parent to bill - the handoff lane now demotes first, and the
        // partial unique index makes the ambiguous state unrepresentable.
        $this->postHandoff($this->payload([
            'event_id' => '01JCEVENT0000000000000002',
            'guardians' => [
                [
                    'nama' => 'Budi Ramadhani',
                    'hubungan' => 'ayah',
                    'email' => 'budi@example.com',
                    'no_hp' => '081234567890',
                    'is_primary' => false,
                ],
                [
                    'nama' => 'Siti Aminah',
                    'hubungan' => 'ibu',
                    'no_hp' => '081298765432',
                    'is_primary' => true,
                ],
            ],
        ]))->assertStatus(202);

        $billingContacts = $student->guardians()->wherePivot('is_billing_contact', true)->get();
        $this->assertCount(1, $billingContacts);
        $this->assertSame('Siti Aminah', $billingContacts->first()->nama);
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

    /**
     * PMB assigns the Nomor Induk per unit on Kelulusan > Murid Diterima,
     * usually after the first handoff - so it arrives on a student.updated.
     * Unique per unit (2026-09-30), not across the foundation.
     */
    public function test_a_nis_assigned_later_in_pmb_reaches_the_student_through_student_updated(): void
    {
        $year = ['academic_year' => '2027/2028'];
        $this->postHandoff($this->payload(['student' => $year]))->assertStatus(202);

        $this->postHandoff($this->payload([
            'event' => 'student.updated',
            'event_id' => '01JCEVENT0000000000000009',
            'student' => $year + ['nis' => '262701142'],
        ]))->assertStatus(202);

        $this->assertSame('262701142', Student::firstWhere('pmb_student_ulid', '01JCSTUDENT000000000000001')->nis);

        // An event without a nis leaves the one already there alone.
        $this->postHandoff($this->payload([
            'event' => 'student.updated',
            'event_id' => '01JCEVENT0000000000000010',
            'student' => $year,
        ]))->assertStatus(202);

        $this->assertSame('262701142', Student::firstWhere('pmb_student_ulid', '01JCSTUDENT000000000000001')->nis);
    }

    public function test_the_same_nis_may_exist_in_two_units_but_not_twice_in_one(): void
    {
        $sd = SchoolUnit::firstWhere('code', 'SD-SAKINAH');
        $smp = SchoolUnit::create(['code' => 'SMP-X', 'label' => 'SMP X', 'jenjang_group' => 'smp']);

        Student::create(['nama_lengkap' => 'A', 'jenis_kelamin' => 'L', 'school_unit_id' => $sd->id, 'nis' => '1001']);
        Student::create(['nama_lengkap' => 'B', 'jenis_kelamin' => 'L', 'school_unit_id' => $smp->id, 'nis' => '1001']);

        $this->assertSame(2, Student::where('nis', '1001')->count());

        $this->expectException(QueryException::class);
        Student::create(['nama_lengkap' => 'C', 'jenis_kelamin' => 'L', 'school_unit_id' => $sd->id, 'nis' => '1001']);
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

    public function test_a_guardian_who_never_opened_the_invitation_can_still_get_in(): void
    {
        $this->postHandoff($this->payload())->assertStatus(202);

        $user = User::firstWhere('email', 'budi@example.com');
        $this->assertFalse($user->hasActivated());

        // The invitation is a welcome, not a gate. A parent who deleted the
        // email is not locked out: requesting a code proves the same thing the
        // link would have, and activates the account on the way through.
        $this->postJson('/api/auth/otp/request', ['identifier' => 'budi@example.com'])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        $this->assertDatabaseCount('login_otps', 1);
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

    /**
     * Invitations go by email only (2026-10-14): a primary father with no
     * email used to get the login - and an invitation over WhatsApp that
     * never arrived - while the mother's email went unused.
     */
    public function test_the_login_goes_to_the_guardian_with_an_email_when_the_primary_has_none(): void
    {
        // Set outright: payload() merges recursively and would hand the
        // father the default guardian's email.
        $payload = $this->payload(['event_id' => '01JCEVENT0000000000000009']);
        $payload['guardians'] = [
            ['nama' => 'Ayah Tanpa Email', 'hubungan' => 'ayah', 'no_hp' => '081200000101', 'is_primary' => true],
            ['nama' => 'Ibu Punya Email', 'hubungan' => 'ibu', 'email' => 'ibu.punya@example.com', 'no_hp' => '081200000102', 'is_primary' => false],
        ];
        $this->postHandoff($payload)->assertStatus(202);

        $mother = \App\Models\Guardian::where('nama', 'Ibu Punya Email')->first();
        $father = \App\Models\Guardian::where('nama', 'Ayah Tanpa Email')->first();

        $this->assertNotNull($mother->user_id);
        $this->assertNull($father->user_id);
        $this->assertSame('ibu.punya@example.com', User::find($mother->user_id)->email);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('ibu.punya@example.com', $this->sentMail[0]['to']);
    }

    public function test_contacts_from_pmb_are_normalised_so_a_sibling_finds_the_same_parent(): void
    {
        // The merge of 2026-10-26 once dropped this (audit T52) without a
        // single test noticing: PMB sends the form as typed, and a raw
        // "+62…" or mixed-case email misses the blind-index lookup.
        $first = $this->payload();
        $first['guardians'] = [
            ['nama' => 'Budi Ramadhani', 'hubungan' => 'ayah', 'email' => 'Budi@Example.COM', 'no_hp' => '+62 812-3456-7890', 'is_primary' => true],
        ];
        $this->postHandoff($first)->assertStatus(202);

        $second = $this->payload([
            'event_id' => '01JCEVENT0000000000000010',
            'student' => ['pmb_ulid' => '01JCSTUDENT000000000000010', 'no_pendaftaran' => 'PMB-2026-00310', 'nama_lengkap' => 'Adik Aisyah', 'nisn' => '0123456710', 'nik' => '3273010101140010'],
        ]);
        $second['guardians'] = [
            ['nama' => 'Budi Ramadhani', 'hubungan' => 'ayah', 'email' => 'budi@example.com', 'no_hp' => '081234567890', 'is_primary' => true],
        ];
        $this->postHandoff($second)->assertStatus(202);

        $this->assertSame(1, Guardian::count());
        $this->assertSame(1, User::where('role', 'orangtua')->count());

        $guardian = Guardian::first();
        $this->assertSame('081234567890', $guardian->no_hp);
        $this->assertSame('budi@example.com', User::find($guardian->user_id)->email);
        $this->assertCount(2, $guardian->students);
    }

    public function test_a_cancelled_registration_consumes_the_parents_live_invitation(): void
    {
        // Audit T53-a, also dropped by the 2026-10-26 merge: the account is
        // disabled, and the link still sitting in the parent's inbox must
        // not sign it back in for the rest of its 7 days.
        $this->postHandoff($this->payload())->assertStatus(202);
        $user = User::where('email', 'budi@example.com')->first();
        $this->assertSame(1, AccountInvitation::where('user_id', $user->id)->whereNull('used_at')->count());

        $this->postHandoff($this->payload([
            'event' => 'student.cancelled',
            'event_id' => '01JCEVENT0000000000000011',
            'reason' => 'Uang pangkal dikembalikan',
        ]))->assertStatus(202);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('dropped_out', Student::first()->status);
        $this->assertSame(0, AccountInvitation::where('user_id', $user->id)->whereNull('used_at')->count());
    }

    private function tokenFromLastMail(): string
    {
        $url = $this->sentMail[array_key_last($this->sentMail)]['data']['activation_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
