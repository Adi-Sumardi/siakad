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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The failed-notification sweep (audit C2): a failed row gets a bounded
 * second chance - same row updated, never a duplicate, three attempts over
 * 24 hours, and login_otp never at all. The bounds are the feature: without
 * them a retry sweep is just a way to spam a family about one bill.
 */
class NotificationRetryTest extends TestCase
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

        // The same gateway-binding pattern as BillReminderTest, plus a
        // mutable failure flag so one test can watch a send fail and then
        // watch the sweep succeed at it.
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

    /** A student with one open bill due in a week, and an emailed billing contact. */
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

    public function test_a_failed_bill_reminder_is_retried_and_the_same_row_becomes_sent(): void
    {
        $row = $this->failedReminderRow();
        $ulid = $row->ulid;

        $this->assertSame('failed', $row->status);
        $this->assertSame(1, $row->attempts);

        // The gateway recovered by the time the sweep runs.
        $this->mailShouldFail = false;
        $this->artisan('notifications:retry-failed')->assertSuccessful();

        // One delivery, one row: the failed row flipped in place, no new
        // history was written, and the BillReminder beat is not doubled.
        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertDatabaseHas('notification_logs', [
            'ulid' => $ulid,
            'status' => 'sent',
            'attempts' => 2,
            'error' => null,
        ]);
        $this->assertNotNull($row->fresh()->sent_at);
        $this->assertCount(2, $this->sentMail); // the original failure + the retry
        $this->assertSame('bill_reminder', $this->sentMail[1]['template']);
        $this->assertSame('budi@example.com', $this->sentMail[1]['to']);
        $this->assertSame(1, \App\Models\BillReminder::count());
    }

    public function test_a_row_that_keeps_failing_increments_attempts_and_stays_failed(): void
    {
        $row = $this->failedReminderRow();
        Log::spy();

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'failed', 'attempts' => 2]);
        $this->assertDatabaseCount('notification_logs', 1);

        // Third attempt reaches the cap while still failing - the sweep
        // leaves one loud line behind and never picks the row up again.
        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'failed', 'attempts' => 3]);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_cap_exhausted_rows_are_not_picked_again(): void
    {
        $this->failedRow(['attempts' => 3]);

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertCount(0, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['attempts' => 3]);
    }

    public function test_login_otp_rows_are_excluded(): void
    {
        $this->failedRow(['template' => 'login_otp', 'payload' => ['otp_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']]);

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertCount(0, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['template' => 'login_otp', 'attempts' => 1]);
    }

    public function test_rows_older_than_24h_are_excluded(): void
    {
        $row = $this->failedRow();
        $row->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertCount(0, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'attempts' => 1]);
    }

    public function test_dry_run_sends_nothing_and_changes_nothing(): void
    {
        $row = $this->failedReminderRow();

        $this->artisan('notifications:retry-failed', ['--dry-run' => true])
            ->expectsOutputToContain('bill_reminder')
            ->assertSuccessful();

        $this->assertCount(1, $this->sentMail); // only the original failed send
        $this->assertDatabaseHas('notification_logs', ['ulid' => $row->ulid, 'status' => 'failed', 'attempts' => 1]);
    }

    public function test_a_row_whose_notifiable_is_gone_fails_gracefully_without_killing_the_sweep(): void
    {
        $healthy = $this->failedReminderRow();
        $this->failedRow(); // notifiable_id 999999 - nothing resolves

        $this->mailShouldFail = false;
        $this->artisan('notifications:retry-failed')->assertSuccessful();

        // The broken row burns its attempt quietly; the healthy one still
        // gets its second chance.
        $this->assertDatabaseHas('notification_logs', ['ulid' => $healthy->ulid, 'status' => 'sent', 'attempts' => 2]);
        $this->assertDatabaseCount('notification_logs', 2);
    }

    public function test_summary_counts_for_a_central_admin(): void
    {
        $admin = $this->staff('admin');

        $this->failedRow(); // failed, within the 24h window

        $threeDays = $this->failedRow(['template' => 'payment_receipt']);
        $threeDays->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        // Older than the 7-day aggregate and a healthy row: counted nowhere.
        $eightDays = $this->failedRow();
        $eightDays->forceFill(['created_at' => now()->subDays(8)])->saveQuietly();
        NotificationLog::create([
            'channel' => 'email', 'template' => 'bill_reminder', 'recipient' => 'x@example.com',
            'payload' => ['kind' => 'h7'], 'status' => 'sent', 'sent_at' => now(),
            'notifiable_type' => Bill::class, 'notifiable_id' => 1,
        ]);

        // Exhausted: the sweep will never look at it again.
        $exhausted = $this->failedRow(['attempts' => 3]);
        $exhausted->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $failures = $this->actingAs($admin)->getJson('/api/admin/notification-failures')
            ->assertOk()
            ->json('failures');

        $this->assertSame(1, $failures['failed_24h']);
        $this->assertSame(3, $failures['failed_7d']);
        $this->assertSame(1, $failures['exhausted_7d']);
        $this->assertSame('bill_reminder', $failures['top_templates'][0]['template']);
        $this->assertNotNull($failures['last_failed_at']);
    }

    public function test_a_unit_admin_cannot_reach_the_summary(): void
    {
        $this->actingAs($this->staff('admin_unit'))
            ->getJson('/api/admin/notification-failures')
            ->assertForbidden();
    }

    public function test_guests_cannot_reach_the_summary(): void
    {
        $this->getJson('/api/admin/notification-failures')->assertUnauthorized();
    }

    public function test_a_failed_va_issued_whatsapp_is_retried_by_the_sweep(): void
    {
        $guardian = \App\Models\Guardian::create([
            'nama' => 'Wali VA',
            'hubungan' => 'ayah',
            'no_hp' => '081299900011',
        ]);

        $payment = \App\Models\Payment::create([
            'payment_number' => 'YAPI-CAM-2026-0001',
            'payer_guardian_id' => $guardian->id,
            'amount' => 900000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '8020092627000042',
                'bank_name' => 'Bank Muamalat',
            ],
        ]);

        // The first push dies at the gateway; the row is the paper trail.
        $this->whatsappShouldFail = true;
        app(\App\Services\Billing\VaIssuedNotifier::class)->notify($payment);

        $row = NotificationLog::query()->where('template', 'va_issued')->sole();
        $this->assertSame('failed', $row->status);

        // The gateway recovered by the time the sweep runs.
        $this->whatsappShouldFail = false;
        $this->artisan('notifications:retry-failed')->assertSuccessful();

        $this->assertDatabaseHas('notification_logs', [
            'ulid' => $row->ulid,
            'status' => 'sent',
            'attempts' => 2,
        ]);
        $this->assertCount(2, $this->sentWhatsApp);
        $this->assertStringContainsString('8020092627000042', $this->sentWhatsApp[1]['message']);
        $this->assertSame('081299900011', $this->sentWhatsApp[1]['phone']);
    }

    public function test_a_va_whose_payment_already_settled_is_never_repushed(): void
    {
        $guardian = \App\Models\Guardian::create([
            'nama' => 'Wali VA Lunas',
            'hubungan' => 'ibu',
            'no_hp' => '081299900012',
        ]);

        $payment = \App\Models\Payment::create([
            'payment_number' => 'YAPI-CAM-2026-0002',
            'payer_guardian_id' => $guardian->id,
            'amount' => 900000,
            'method' => 'virtual_account',
            'status' => 'completed',
            'paid_at' => now(),
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '8020092627000043',
                'bank_name' => 'Bank Muamalat',
            ],
        ]);

        $row = NotificationLog::create([
            'channel' => 'whatsapp',
            'template' => 'va_issued',
            'recipient' => '081299900012',
            'payload' => [],
            'status' => 'failed',
            'error' => 'gateway sedang down',
            'notifiable_type' => \App\Models\Payment::class,
            'notifiable_id' => $payment->id,
        ]);

        $this->artisan('notifications:retry-failed')->assertSuccessful();

        // A settled payment's VA must not arrive as if it were still open.
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertStringContainsString('completed', (string) $row->error);
        $this->assertCount(0, $this->sentWhatsApp);
    }
}
