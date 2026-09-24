<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillReminder;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillReminderSender;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\QontakWhatsAppGateway;
use App\Services\Notification\NotificationRetryService;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reminders exist so a family notices a bill before it is overdue, and stop
 * existing once they have been sent - the fatigue case (four messages about
 * one SPP) is worse than the silence case, so every send is guarded by a
 * unique (bill_id, kind, channel) row rather than trusted to "the job only
 * runs once". The bill's own status is re-read from the database at dispatch
 * time, so paying between the scheduler's query and its send is every bit as
 * silencing as paying before the run started.
 */
class BillReminderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    /** @var list<array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

    /** @var list<array{phone: string, toName: string, templateId: string, bodyValues: array}> */
    private array $sentQontakTemplates = [];

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

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

        $this->app->bind(WhatsAppGateway::class, fn () => new class($this->sentWhatsApp) implements WhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendMessage(string $phone, string $message): NotificationResult
            {
                $this->sent[] = compact('phone', 'message');

                return NotificationResult::ok();
            }
        });

        // SPP reminders go through Qontak's approved template instead of
        // free-text Sendago (see BillReminderSender::queueSppReminderTemplate()) -
        // faked the same way OtpLoginTest/SelectionAnnouncementTest fake the
        // concrete gateway/job, capturing the call rather than hitting Qontak.
        $this->app->bind(QontakWhatsAppGateway::class, fn () => new class($this->sentQontakTemplates) extends QontakWhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendTemplate(string $phone, string $toName, string $templateId, array $bodyValues, array $buttonValues = []): NotificationResult
            {
                $this->sent[] = compact('phone', 'toName', 'templateId', 'bodyValues');

                return NotificationResult::ok();
            }
        });

        // ensureReminderVaPair() otherwise calls the real e-SPP Billing API -
        // faked to return fixed, obviously-fake VA numbers rather than
        // mocking BillingApiClient's HTTP layer directly.
        $this->app->bind(BillingApiGateway::class, fn () => new class extends BillingApiGateway
        {
            public function __construct() {}

            public function ensureReminderVaPair(\App\Models\Bill $bill, \App\Models\Guardian $payer): array
            {
                return [
                    'muamalat' => ['va_number' => '8020012627000001', 'bank_name' => 'Bank Muamalat'],
                    'bsi' => ['va_number' => '7895012627000001', 'bank_name' => 'Bank Syariah Indonesia (BSI)'],
                ];
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

    /** A student with one open bill due on the given date, and a guardian to notify. */
    private function billedStudentDueOn(Carbon $dueDate, ?string $email = 'budi@example.com', ?string $phone = null): Bill
    {
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
            'description' => 'SPP Agustus 2026',
            'subtotal' => 650000,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => 650000,
            'paid_amount' => 0,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => $dueDate,
            'allow_installment' => false,
            'issued_at' => now(),
        ]);

        $guardian = Guardian::create([
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'email' => $email,
        ]);

        if ($phone) {
            $guardian->no_hp = $phone;
            $guardian->save();
        }

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah',
            'is_primary' => true,
            'is_billing_contact' => true,
        ]);

        return $bill->fresh();
    }

    public function test_a_bill_due_in_seven_days_gets_the_h7_reminder(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(7));

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sentMail);
        $this->assertSame('bill_reminder', $this->sentMail[0]['template']);
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'kind' => 'h7']);
    }

    public function test_the_command_classifies_bills_by_their_actual_due_date(): void
    {
        $sender = app(BillReminderSender::class);

        $h7 = $this->billedStudentDueOn(now()->addDays(7));
        $h1 = $this->billedStudentDueOn(now()->addDays(1));
        $overdue = $this->billedStudentDueOn(now()->subDays(3));
        $tooSoonForAnyBeat = $this->billedStudentDueOn(now()->addDays(3));

        $this->assertSame('h7', $sender->kindFor($h7));
        $this->assertSame('h1', $sender->kindFor($h1));
        $this->assertSame('overdue', $sender->kindFor($overdue));
        $this->assertNull($sender->kindFor($tooSoonForAnyBeat));
    }

    public function test_a_paid_bill_is_never_a_reminder_candidate(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(1));
        $bill->update(['status' => 'paid', 'remaining_amount' => 0]);

        // A settled bill sitting at exactly H-1 must not nag a family that has
        // already paid.
        $this->assertNull(app(BillReminderSender::class)->kindFor($bill->fresh()));
    }

    public function test_the_same_beat_is_never_sent_twice(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(7));
        $sender = app(BillReminderSender::class);

        $this->assertTrue($sender->send($bill, 'h7'));
        $this->assertFalse($sender->send($bill, 'h7'));

        // The scheduler firing twice in a day must not double the message.
        $this->assertCount(1, $this->sentMail);
        $this->assertSame(1, BillReminder::where('bill_id', $bill->id)->count());
    }

    public function test_a_bill_can_receive_all_three_beats_across_its_lifetime(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(7));
        $sender = app(BillReminderSender::class);

        $this->assertTrue($sender->send($bill, 'h7'));
        $this->assertTrue($sender->send($bill, 'h1'));
        $this->assertTrue($sender->send($bill, 'overdue'));

        $this->assertCount(3, $this->sentMail);
        $this->assertSame(3, BillReminder::where('bill_id', $bill->id)->count());
    }

    public function test_a_guardian_with_only_a_phone_number_is_reminded_over_whatsapp(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(7), email: null, phone: '081234567890');

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertTrue($sent);
        $this->assertEmpty($this->sentMail);
        $this->assertEmpty($this->sentWhatsApp);
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'channel' => 'whatsapp']);

        // SPP goes through Qontak's approved 'reminder_spp' template
        // (QontakWhatsAppGateway::sendTemplate()), not the free-text Sendago
        // path - international 62xxx phone form, both banks' VA numbers as
        // separate body values.
        $this->assertCount(1, $this->sentQontakTemplates);
        $sent0 = $this->sentQontakTemplates[0];
        $this->assertSame('6281234567890', $sent0['phone']);
        $this->assertSame('Aisyah Nur Ramadhani', $sent0['bodyValues'][0]);
        $this->assertSame('8020012627000001', $sent0['bodyValues'][3]);
        // BSI's fixed 4-digit institution code (7895) is stripped before
        // this value - the template shows it separately as static text.
        $this->assertSame('012627000001', $sent0['bodyValues'][4]);

        $log = \App\Models\NotificationLog::where('channel', 'whatsapp')->where('template', 'reminder_spp')->first();
        $this->assertNotNull($log);
        $this->assertSame('queued', $log->status);
    }

    public function test_a_bill_with_no_reachable_guardian_is_skipped_not_failed(): void
    {
        $bill = $this->billedStudentDueOn(now()->addDays(7), email: null, phone: null);

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertFalse($sent);
        $this->assertEmpty($this->sentMail);
        $this->assertDatabaseCount('bill_reminders', 0);
    }

    public function test_the_scheduled_command_sends_across_many_bills_and_is_idempotent(): void
    {
        $this->billedStudentDueOn(now()->addDays(7));
        $this->billedStudentDueOn(now()->addDays(1));
        $this->billedStudentDueOn(now()->addDays(3)); // no beat today

        $this->artisan('bills:send-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sentMail);

        // Running again the same day must not re-send either one.
        $this->artisan('bills:send-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sentMail);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->billedStudentDueOn(now()->addDays(7));

        $this->artisan('bills:send-reminders --dry-run')->assertSuccessful();

        $this->assertEmpty($this->sentMail);
        $this->assertDatabaseCount('bill_reminders', 0);
    }

    /** Marks a bill fully settled the way PaymentAllocator::settle() leaves it. */
    private function settle(Bill $bill): Bill
    {
        $bill->update(['status' => 'paid', 'remaining_amount' => 0]);

        return $bill->fresh();
    }

    public function test_a_reminder_fans_out_to_email_and_whatsapp_for_a_contact_with_both(): void
    {
        // Exercises the free-text Sendago lane: with no SPP template set,
        // an SPP bill falls back to it exactly like a non-SPP fee type does.
        // The Qontak template lane has its own test above.
        config(['services.qontak.spp_reminder_template_id' => null]);

        $bill = $this->billedStudentDueOn(now()->addDays(7), email: 'budi@example.com', phone: '081234567890');

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        // Both deliveries carry the same bill snapshot (amount, due date, kind).
        $this->assertSame('bill_reminder', $this->sentMail[0]['template']);
        $this->assertStringContainsString('SPP', $this->sentWhatsApp[0]['message']);
        // One sent-fact per channel, not one for the beat.
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'kind' => 'h7', 'channel' => 'email']);
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'kind' => 'h7', 'channel' => 'whatsapp']);
        $this->assertSame(2, BillReminder::where('bill_id', $bill->id)->count());
        $this->assertDatabaseCount('notification_logs', 2);

        // The scheduler firing again the same day adds nothing on either channel.
        app(BillReminderSender::class)->send($bill->fresh(), 'h7');
        $this->artisan('bills:send-reminders')->assertSuccessful();
        $this->assertCount(1, $this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame(2, BillReminder::where('bill_id', $bill->id)->count());
    }

    public function test_a_bill_paid_between_the_query_and_the_send_still_gets_no_reminder(): void
    {
        // The scheduler loaded this bill while it was open; the family paid in
        // the seconds since. The in-memory snapshot must not win.
        $bill = $this->billedStudentDueOn(now()->addDays(7));
        $staleOpenCopy = $bill->fresh();
        $this->settle($bill);

        $sent = app(BillReminderSender::class)->send($staleOpenCopy, 'h7');

        $this->assertFalse($sent);
        $this->assertEmpty($this->sentMail);
        $this->assertEmpty($this->sentWhatsApp);
        $this->assertDatabaseCount('bill_reminders', 0);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_payment_after_the_first_reminder_blocks_every_later_beat(): void
    {
        // 08:00 - the H-7 reminder goes out because the bill is unpaid.
        $bill = $this->billedStudentDueOn(now()->addDays(7));
        $this->assertTrue(app(BillReminderSender::class)->send($bill, 'h7'));
        $this->assertCount(1, $this->sentMail);

        // 10:00 - the family pays. 12:00 - the scheduler runs again, now on
        // the H-1 beat: the settled bill must stay silent.
        $this->settle($bill);
        $bill->update(['due_date' => now()->addDay()]);

        $this->assertFalse(app(BillReminderSender::class)->send($bill->fresh(), 'h1'));
        $this->artisan('bills:send-reminders')->assertSuccessful();

        $this->assertCount(1, $this->sentMail); // still only the morning's H-7
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'kind' => 'h7']);
        $this->assertFalse(BillReminder::where('bill_id', $bill->id)->where('kind', 'h1')->exists());
    }

    public function test_among_many_bills_only_the_still_open_ones_are_reminded(): void
    {
        $h7 = $this->billedStudentDueOn(now()->addDays(7));
        $h1 = $this->billedStudentDueOn(now()->addDays(1));
        $overdue = $this->billedStudentDueOn(now()->subDays(3));
        $paidH7 = $this->billedStudentDueOn(now()->addDays(7));
        $this->settle($paidH7);

        $this->artisan('bills:send-reminders')->assertSuccessful();

        $this->assertCount(3, $this->sentMail);
        $this->assertTrue(BillReminder::where('bill_id', $h7->id)->exists());
        $this->assertTrue(BillReminder::where('bill_id', $h1->id)->exists());
        $this->assertTrue(BillReminder::where('bill_id', $overdue->id)->exists());
        $this->assertFalse(BillReminder::where('bill_id', $paidH7->id)->exists());
    }

    public function test_an_email_failure_does_not_stop_the_whatsapp_send(): void
    {
        // Exercises the free-text Sendago lane: with no SPP template set,
        // an SPP bill falls back to it exactly like a non-SPP fee type does.
        // The Qontak template lane has its own test above.
        config(['services.qontak.spp_reminder_template_id' => null]);

        $this->app->forgetInstance(MailGateway::class);
        $this->app->bind(MailGateway::class, fn () => new class implements MailGateway
        {
            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                return NotificationResult::fail('SMTP sedang down');
            }
        });

        $bill = $this->billedStudentDueOn(now()->addDays(7), email: 'budi@example.com', phone: '081234567890');

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        // The WhatsApp half still counts as reaching the family.
        $this->assertTrue($sent);
        $this->assertEmpty($this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'failed', 'error' => 'SMTP sedang down']);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'whatsapp', 'status' => 'sent']);
        // The failed email is the retry sweep's to pick up, not lost.
        $this->assertSame(1, app(NotificationRetryService::class)->due()->count());
    }

    public function test_a_whatsapp_failure_does_not_stop_the_email_send(): void
    {
        // Exercises the free-text Sendago lane: with no SPP template set,
        // an SPP bill falls back to it exactly like a non-SPP fee type does.
        // The Qontak template lane has its own test above.
        config(['services.qontak.spp_reminder_template_id' => null]);

        $this->app->forgetInstance(WhatsAppGateway::class);
        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway
        {
            public function sendMessage(string $phone, string $message): NotificationResult
            {
                return NotificationResult::fail('gateway WhatsApp menolak');
            }
        });

        $bill = $this->billedStudentDueOn(now()->addDays(7), email: 'budi@example.com', phone: '081234567890');

        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'whatsapp', 'status' => 'failed', 'error' => 'gateway WhatsApp menolak']);
    }

    public function test_a_crashing_gateway_is_contained_and_the_other_channel_still_sends(): void
    {
        // Exercises the free-text Sendago lane: with no SPP template set,
        // an SPP bill falls back to it exactly like a non-SPP fee type does.
        // The Qontak template lane has its own test above.
        config(['services.qontak.spp_reminder_template_id' => null]);

        $this->app->forgetInstance(MailGateway::class);
        $this->app->bind(MailGateway::class, fn () => new class implements MailGateway
        {
            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                throw new \RuntimeException('connection reset by peer');
            }
        });

        $bill = $this->billedStudentDueOn(now()->addDays(7), email: 'budi@example.com', phone: '081234567890');

        // The exception must not escape the sender, let alone the run.
        $sent = app(BillReminderSender::class)->send($bill, 'h7');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'channel' => 'whatsapp']);
    }

    public function test_one_bill_throwing_does_not_stop_the_run_for_the_others(): void
    {
        $stub = new class(app(MailGateway::class), app(WhatsAppGateway::class), app(BillingApiGateway::class)) extends BillReminderSender
        {
            public ?int $throwForBill = null;

            public function send(Bill $bill, string $kind): bool
            {
                if ($this->throwForBill === $bill->id) {
                    throw new \RuntimeException('database glitch');
                }

                return parent::send($bill, $kind);
            }
        };
        $this->app->instance(BillReminderSender::class, $stub);

        $doomed = $this->billedStudentDueOn(now()->addDays(1));
        $stub->throwForBill = $doomed->id;
        $healthy = $this->billedStudentDueOn(now()->addDays(7));

        $this->artisan('bills:send-reminders')->assertSuccessful();

        $this->assertCount(1, $this->sentMail);
        $this->assertTrue(BillReminder::where('bill_id', $healthy->id)->exists());
        $this->assertFalse(BillReminder::where('bill_id', $doomed->id)->exists());
    }

    public function test_the_in_app_channel_never_lists_a_paid_bill(): void
    {
        // The wali navbar bell renders straight from /api/wali/bills?status=open,
        // refreshed every minute - that live feed IS the in-app reminder
        // channel, and it can only ever show bills the database still considers
        // open. Settling the bill must drop it from the feed immediately.
        $bill = $this->billedStudentDueOn(now()->addDays(5));

        $user = User::create([
            'name' => 'Budi Ramadhani',
            'email' => 'budi.inapp@example.com',
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $bill->student->guardians->first()->update(['user_id' => $user->id]);

        $this->actingAs($user)->getJson('/api/wali/bills?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'bills');

        $this->settle($bill);

        $this->actingAs($user)->getJson('/api/wali/bills?status=open')
            ->assertOk()
            ->assertJsonCount(0, 'bills');
    }
}
