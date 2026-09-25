<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\NotificationLog;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillReminderSender;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ruang kontrol's notifikasi leg (/admin/monitoring): the failed rows
 * behind the dashboard card's counts, plus the manual resend. The contract
 * that matters most: a manual resend is a HUMAN decision - it bypasses the
 * sweep's cap and window, but never the exclusions, and "still failing" is
 * reported as a result, not hidden behind an error.
 */
class NotificationLogMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    /** @var list<array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

    private bool $mailShouldFail = false;

    private bool $whatsappShouldFail = false;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MailGateway::class, fn () => new class($this->sentMail, $this->mailShouldFail) implements MailGateway
        {
            public function __construct(private array &$sent, private bool &$shouldFail) {}

            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                $this->sent[] = compact('to', 'template', 'data');

                return $this->shouldFail
                    ? NotificationResult::fail('gateway sedang down')
                    : NotificationResult::ok();
            }
        });

        $this->app->bind(WhatsAppGateway::class, fn () => new class($this->sentWhatsApp, $this->whatsappShouldFail) implements WhatsAppGateway
        {
            public function __construct(private array &$sent, private bool &$shouldFail) {}

            public function sendMessage(string $phone, string $message): NotificationResult
            {
                $this->sent[] = compact('phone', 'message');

                return $this->shouldFail
                    ? NotificationResult::fail('gateway sedang down')
                    : NotificationResult::ok();
            }
        });

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        FeeRate::create([
            'fee_type_id' => $this->spp->id,
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'amount' => 650000,
            'due_day' => 10,
        ]);
    }

    private function staff(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    /** A real bill_reminder row whose original send failed - the resend path end to end. */
    private function failedReminderRow(): NotificationLog
    {
        $this->mailShouldFail = true;

        $student = Student::create([
            'nama_lengkap' => 'Aisyah Nur Ramadhani',
            'nama_panggilan' => 'Aisyah',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $bill = Bill::create([
            'bill_number' => 'SPP/'.uniqid(),
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:'.uniqid(),
            'description' => 'SPP September 2026',
            'subtotal' => 650000,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => 650000,
            'paid_amount' => 0,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'allow_installment' => false,
            'issued_at' => now(),
        ]);

        $guardian = \App\Models\Guardian::create([
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'email' => 'budi@example.com',
        ]);

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah',
            'is_primary' => true,
            'is_billing_contact' => true,
        ]);

        app(BillReminderSender::class)->send($bill->fresh(), 'h7');

        return NotificationLog::query()->where('template', 'bill_reminder')->sole();
    }

    private function failedRow(array $overrides = []): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'channel' => 'email',
            'template' => 'bill_reminder',
            'recipient' => 'budi@example.com',
            'payload' => ['kind' => 'h7'],
            'status' => 'failed',
            'error' => 'gateway sedang down',
            'notifiable_type' => Bill::class,
            'notifiable_id' => 999999,
        ], $overrides));
    }

    public function test_the_failed_list_is_the_default_view_and_leaks_no_payload(): void
    {
        $failed = $this->failedRow();
        $this->failedRow(['status' => 'sent', 'sent_at' => now()]);

        $body = $this->actingAs($this->staff('admin'))->getJson('/api/admin/notification-logs')
            ->assertOk()
            ->json('notifications');

        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame($failed->ulid, $body['data'][0]['ulid']);
        // Masked since audit T51-b - recognizable, not re-publishable.
        $this->assertSame('b***@example.com', $body['data'][0]['recipient']);
        $this->assertSame(1, $body['data'][0]['attempts']);
        $this->assertArrayNotHasKey('payload', $body['data'][0]);
        $this->assertArrayNotHasKey('notifiable_id', $body['data'][0]);
    }

    public function test_status_template_and_channel_filters_narrow_the_list(): void
    {
        $this->failedRow(); // failed, email, bill_reminder
        $this->failedRow(['template' => 'login_otp', 'payload' => ['otp_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']]);
        $this->failedRow(['channel' => 'whatsapp', 'status' => 'sent', 'sent_at' => now()]);

        $json = fn (string $query) => $this->actingAs($this->staff('admin'))
            ->getJson("/api/admin/notification-logs?{$query}")
            ->assertOk()
            ->json('notifications.meta.total');

        $this->assertSame(3, $json('status=all'));
        $this->assertSame(1, $json('status=all&template=login_otp'));
        $this->assertSame(1, $json('status=all&channel=whatsapp'));
        $this->assertSame(2, $json('status=failed'));
    }

    public function test_the_monitoring_lists_are_central_admin_only(): void
    {
        $unitAdmin = $this->staff('admin_unit');

        foreach (['/api/admin/notification-logs', '/api/admin/integration-events', '/api/admin/failed-jobs'] as $path) {
            $this->actingAs($unitAdmin)->getJson($path)->assertForbidden();
        }
    }

    public function test_the_monitoring_lists_reject_guests(): void
    {
        // Separate from the 403 test: actingAs sticks for the whole test, so
        // a "guest" request after it would still be signed in.
        foreach (['/api/admin/notification-logs', '/api/admin/integration-events', '/api/admin/failed-jobs'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_a_manual_resend_sends_again_and_updates_the_same_row(): void
    {
        $row = $this->failedReminderRow();
        $this->mailShouldFail = false;

        $body = $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/notification-logs/{$row->ulid}/resend")
            ->assertOk()
            ->json();

        $this->assertTrue($body['result']['success']);
        $this->assertSame('sent', $body['notification']['status']);

        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'sent', 'attempts' => 2]);
        $this->assertCount(2, $this->sentMail); // original failure + manual resend
        $this->assertDatabaseHas('activity_logs', ['action' => 'notification.resent']);
    }

    public function test_a_manual_resend_reports_a_still_failing_send(): void
    {
        $row = $this->failedReminderRow();
        $this->mailShouldFail = true; // still down

        $body = $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/notification-logs/{$row->ulid}/resend")
            ->assertOk()
            ->json();

        $this->assertFalse($body['result']['success']);
        $this->assertSame('gateway sedang down', $body['result']['message']);
        $this->assertSame('failed', $body['notification']['status']);
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'failed', 'attempts' => 2]);
    }

    public function test_a_manual_resend_refuses_login_otp(): void
    {
        $row = $this->failedRow(['template' => 'login_otp', 'payload' => ['otp_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']]);

        $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/notification-logs/{$row->ulid}/resend")
            ->assertStatus(422);

        $this->assertCount(0, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'attempts' => 1]);
    }

    public function test_a_manual_resend_refuses_an_already_sent_row(): void
    {
        $row = $this->failedRow(['status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/notification-logs/{$row->ulid}/resend")
            ->assertStatus(422);

        $this->assertCount(0, $this->sentMail);
    }

    public function test_a_manual_resend_bypasses_the_sweep_attempt_cap(): void
    {
        // The sweep gave up on this row (attempts = 3); a human says send it
        // anyway - and it goes out as attempt 4.
        $row = $this->failedReminderRow();
        $row->forceFill(['attempts' => 3])->saveQuietly();
        $this->mailShouldFail = false;

        $body = $this->actingAs($this->staff('admin'))
            ->postJson("/api/admin/notification-logs/{$row->ulid}/resend")
            ->assertOk()
            ->json();

        $this->assertTrue($body['result']['success']);
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'sent', 'attempts' => 4]);
    }
}
