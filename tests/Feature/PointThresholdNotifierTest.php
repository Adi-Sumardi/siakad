<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\PointRule;
use App\Models\PointThreshold;
use App\Models\PointThresholdNotification;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Points\PointLedger;
use App\Services\Points\PointThresholdNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Once per band per term" is the whole design here - see
 * PointThresholdNotifier's own docblock. A family told once that their child
 * is in "Peringatan 1" should not hear it again every morning the balance sits
 * there; dropping into a worse band is the one case worth a second message.
 */
class PointThresholdNotifierTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    private SchoolUnit $unit;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MailGateway::class, fn () => new class($this->sentMail) implements MailGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                $this->sent[] = compact('to', 'template', 'data');

                return NotificationResult::ok();
            }
        });

        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway
        {
            public function sendMessage(string $phone, string $message): NotificationResult
            {
                return NotificationResult::ok();
            }
        });

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();
        $this->term = Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        PointThreshold::create(['school_unit_id' => null, 'min_points' => -49, 'max_points' => -25, 'label' => 'Peringatan 1', 'notify_guardian' => true]);
        PointThreshold::create(['school_unit_id' => null, 'min_points' => -999, 'max_points' => -50, 'label' => 'Peringatan 2', 'notify_guardian' => true]);
        PointThreshold::create(['school_unit_id' => null, 'min_points' => -24, 'max_points' => 999, 'label' => 'Baik', 'notify_guardian' => false]);
    }

    private function studentWithGuardian(int $balance): Student
    {
        $student = Student::create([
            'nama_lengkap' => 'Aisyah', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Budi', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Budi', 'hubungan' => 'ayah', 'email' => $user->email]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        if ($balance !== 0) {
            $rule = PointRule::create([
                'code' => 'R-'.uniqid(), 'name' => 'Rule', 'category' => 'X',
                'type' => $balance < 0 ? 'violation' : 'merit', 'points' => abs($balance),
            ]);
            $guru = User::create([
                'name' => 'Guru', 'email' => 'guru'.uniqid().'@yapinet.id', 'role' => 'guru',
                'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
            ]);
            app(PointLedger::class)->record($student, $this->term, $rule, $guru, now(), 'Setup');
        }

        return $student->fresh();
    }

    public function test_crossing_into_a_notifiable_band_sends_one_email(): void
    {
        $student = $this->studentWithGuardian(-30);

        $sent = app(PointThresholdNotifier::class)->evaluate($student, $this->term);

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('point_threshold', $this->sentMail[0]['template']);
        $this->assertDatabaseHas('point_threshold_notifications', ['student_id' => $student->id]);
    }

    public function test_a_band_with_notify_guardian_false_sends_nothing(): void
    {
        $student = $this->studentWithGuardian(-5); // "Baik" band, notify_guardian=false

        $sent = app(PointThresholdNotifier::class)->evaluate($student, $this->term);

        $this->assertFalse($sent);
        $this->assertEmpty($this->sentMail);
    }

    public function test_the_same_band_is_never_notified_twice_in_one_term(): void
    {
        $student = $this->studentWithGuardian(-30);
        $notifier = app(PointThresholdNotifier::class);

        $this->assertTrue($notifier->evaluate($student, $this->term));
        $this->assertFalse($notifier->evaluate($student, $this->term));

        $this->assertCount(1, $this->sentMail);
        $this->assertSame(1, PointThresholdNotification::where('student_id', $student->id)->count());
    }

    public function test_dropping_into_a_worse_band_notifies_again(): void
    {
        $student = $this->studentWithGuardian(-30); // Peringatan 1
        $notifier = app(PointThresholdNotifier::class);
        $this->assertTrue($notifier->evaluate($student, $this->term));

        // Falls further, into Peringatan 2 - a distinct threshold row.
        $rule = PointRule::create(['code' => 'R2', 'name' => 'X', 'category' => 'X', 'type' => 'violation', 'points' => 30]);
        $guru = User::create(['name' => 'G', 'email' => 'g2@yapinet.id', 'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now()]);
        app(PointLedger::class)->record($student, $this->term, $rule, $guru, now(), 'Worse');

        $this->assertTrue($notifier->evaluate($student->fresh(), $this->term));
        $this->assertCount(2, $this->sentMail);
    }

    public function test_a_student_with_no_guardian_is_skipped_not_failed(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Tanpa wali', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);
        $rule = PointRule::create(['code' => 'R3', 'name' => 'X', 'category' => 'X', 'type' => 'violation', 'points' => 30]);
        $guru = User::create(['name' => 'G', 'email' => 'g3@yapinet.id', 'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now()]);
        app(PointLedger::class)->record($student, $this->term, $rule, $guru, now(), 'X');

        $sent = app(PointThresholdNotifier::class)->evaluate($student->fresh(), $this->term);

        $this->assertFalse($sent);
        $this->assertEmpty($this->sentMail);
    }

    public function test_the_scheduled_command_evaluates_every_active_student_and_is_idempotent(): void
    {
        $this->studentWithGuardian(-30);
        $this->studentWithGuardian(-5); // not notifiable

        $this->artisan('points:evaluate-thresholds')->assertSuccessful();
        $this->assertCount(1, $this->sentMail);

        $this->artisan('points:evaluate-thresholds')->assertSuccessful();
        $this->assertCount(1, $this->sentMail); // unchanged on a second run
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->studentWithGuardian(-30);

        $this->artisan('points:evaluate-thresholds --dry-run')->assertSuccessful();

        $this->assertEmpty($this->sentMail);
        $this->assertDatabaseCount('point_threshold_notifications', 0);
    }
}
