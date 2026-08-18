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
use App\Services\Billing\BillReminderSender;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reminders exist so a family notices a bill before it is overdue, and stop
 * existing once they have been sent - the fatigue case (four messages about
 * one SPP) is worse than the silence case, so every send is guarded by a
 * unique (bill_id, kind) row rather than trusted to "the job only runs once".
 */
class BillReminderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, template: string, data: array}> */
    private array $sentMail = [];

    /** @var list<array{phone: string, message: string}> */
    private array $sentWhatsApp = [];

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
    private function billedStudentDueOn(\Illuminate\Support\Carbon $dueDate, ?string $email = 'budi@example.com', ?string $phone = null): Bill
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
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertDatabaseHas('bill_reminders', ['bill_id' => $bill->id, 'channel' => 'whatsapp']);
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
}
