<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\PaymentAllocator;
use App\Services\Notification\MailGateway;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\NotificationRetryService;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment receipt is the family's "uang saya sampai" moment, and it must
 * reach them on every channel they have the moment the money settles - not
 * one channel chosen for them, and not only after a retry. settle() is the
 * single choke point that fires it (webhook, poller, cash desk, simulate
 * button), and the wali bell's "Pembayaran diterima" section reads the live
 * payments feed, which is the in-app third channel.
 */
class PaymentReceiptTest extends TestCase
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

    /** A paid bill + settled payment from a guardian reachable on the given channels. */
    private function settledPaymentBy(?string $email = 'budi@example.com', ?string $phone = '081234567890'): Payment
    {
        $student = Student::create([
            'nama_lengkap' => 'Aisyah Nur Ramadhani',
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
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'issued_at' => now(),
        ]);

        $guardian = Guardian::create([
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'email' => $email,
            'no_hp' => $phone,
        ]);

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah',
            'is_primary' => true,
            'is_billing_contact' => true,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY/'.now()->format('Ymd').'/TEST01',
            'payer_guardian_id' => $guardian->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'status' => 'pending',
        ]);

        $allocator = app(PaymentAllocator::class);
        $allocator->allocate($payment, [$bill->id => 650000.0]);
        $allocator->settle($payment);

        return $payment->fresh();
    }

    public function test_a_settled_payment_receipts_the_family_on_email_and_whatsapp_at_once(): void
    {
        $payment = $this->settledPaymentBy();

        // "Sekali bayar, tiga kabar": email and WhatsApp fire inside settle()
        // itself, and the in-app feed (asserted below) reads the payment row
        // that settle() just completed.
        $this->assertCount(1, $this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame('payment_receipt', $this->sentMail[0]['template']);
        $this->assertSame('budi@example.com', $this->sentMail[0]['to']);
        $this->assertStringContainsString('650.000', $this->sentWhatsApp[0]['message']);

        $this->assertDatabaseHas('notification_logs', [
            'template' => 'payment_receipt',
            'channel' => 'email',
            'status' => 'sent',
            'notifiable_id' => $payment->id,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'template' => 'payment_receipt',
            'channel' => 'whatsapp',
            'status' => 'sent',
            'notifiable_id' => $payment->id,
        ]);
    }

    public function test_a_redelivered_webhook_still_receipts_each_channel_exactly_once(): void
    {
        $payment = $this->settledPaymentBy();

        // The gateway retries the callback: settle() runs again, and its own
        // status guard plus the per-channel log row keep both channels silent.
        app(PaymentAllocator::class)->settle($payment->fresh(), 'tx_duplicate');

        $this->assertCount(1, $this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame(2, NotificationLog::where('template', 'payment_receipt')->count());
    }

    public function test_an_email_failure_does_not_stop_the_whatsapp_receipt(): void
    {
        $this->app->forgetInstance(MailGateway::class);
        $this->app->bind(MailGateway::class, fn () => new class implements MailGateway
        {
            public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult
            {
                return NotificationResult::fail('SMTP sedang down');
            }
        });

        $payment = $this->settledPaymentBy();

        $this->assertEmpty($this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'email',
            'status' => 'failed',
            'error' => 'SMTP sedang down',
        ]);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'whatsapp', 'status' => 'sent']);
        // The failed email stays with the retry sweep, and the settle itself
        // never rolled back - the money is in regardless.
        $this->assertSame('completed', $payment->status);
        $this->assertSame(1, app(NotificationRetryService::class)->due()->count());
    }

    public function test_a_whatsapp_failure_does_not_stop_the_email_receipt(): void
    {
        $this->app->forgetInstance(WhatsAppGateway::class);
        $this->app->bind(WhatsAppGateway::class, fn () => new class implements WhatsAppGateway
        {
            public function sendMessage(string $phone, string $message): NotificationResult
            {
                return NotificationResult::fail('gateway WhatsApp menolak');
            }
        });

        $payment = $this->settledPaymentBy();

        $this->assertCount(1, $this->sentMail);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'whatsapp',
            'status' => 'failed',
            'error' => 'gateway WhatsApp menolak',
        ]);
    }

    public function test_the_in_app_feed_lists_the_settled_payment_immediately(): void
    {
        $payment = $this->settledPaymentBy();

        $user = User::create([
            'name' => 'Budi Ramadhani',
            'email' => 'budi@example.com',
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $payment->payer->update(['user_id' => $user->id]);

        // The wali bell's "Pembayaran diterima" section reads exactly this
        // endpoint - a payment that just settled is in it on the next refresh,
        // no notification row required.
        $this->actingAs($user)
            ->getJson('/api/wali/payments')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.ulid', $payment->ulid)
            ->assertJsonPath('payments.0.status', 'completed');
    }

    public function test_a_contact_reachable_on_only_one_channel_gets_it_on_that_one(): void
    {
        $this->settledPaymentBy(email: null, phone: '081234567890');

        $this->assertEmpty($this->sentMail);
        $this->assertCount(1, $this->sentWhatsApp);
        $this->assertSame(1, NotificationLog::where('template', 'payment_receipt')->count());
    }
}
