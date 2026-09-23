<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Services\Billing\PaymentAllocator;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\QontakWhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * receipt_spp_school confirms an SPP payment the moment
 * PaymentAllocator::settle() marks it completed - the counterpart to
 * reminder_spp_school (BillReminderTest), which asks for the money instead
 * of confirming it arrived.
 */
class PaymentReceiptWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{phone: string, toName: string, templateId: string, bodyValues: array}> */
    private array $sentQontakTemplates = [];

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(QontakWhatsAppGateway::class, fn () => new class($this->sentQontakTemplates) extends QontakWhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendTemplate(string $phone, string $toName, string $templateId, array $bodyValues, array $buttonValues = []): NotificationResult
            {
                $this->sent[] = compact('phone', 'toName', 'templateId', 'bodyValues');

                return NotificationResult::ok();
            }
        });

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $this->guardian = Guardian::create([
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'no_hp' => '081234567890',
        ]);
    }

    private function billFor(Student $student, string $description = 'SPP Agustus 2026', float $amount = 650000, ?Carbon $issuedAt = null): Bill
    {
        $bill = Bill::create([
            'bill_number' => 'SPP/'.uniqid(),
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'spp:'.uniqid(),
            'description' => $description,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
            'allow_installment' => false,
            'issued_at' => $issuedAt ?? now(),
        ]);

        $student->guardians()->syncWithoutDetaching([
            $this->guardian->id => ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true],
        ]);

        return $bill->fresh();
    }

    private function studentNamed(string $name): Student
    {
        return Student::create([
            'nama_lengkap' => $name,
            'nama_panggilan' => explode(' ', $name)[0],
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);
    }

    public function test_settling_an_spp_payment_sends_the_receipt_template(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $student = $this->studentNamed('Aisyah Nur Ramadhani');
        $bill = $this->billFor($student);

        $payment = Payment::create([
            'payment_number' => 'PAY/20260821/ABCDEF',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
            'gateway_response' => ['bank_name' => 'Bank Muamalat', 'va_number' => '8020011234567890'],
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 650000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_1');

        $this->assertSame('paid', $bill->fresh()->status);

        $this->assertCount(1, $this->sentQontakTemplates);
        $sent = $this->sentQontakTemplates[0];
        $this->assertSame('6281234567890', $sent['phone']);
        $this->assertSame('receipt-template-uuid', $sent['templateId']);
        $this->assertSame('Aisyah Nur Ramadhani', $sent['bodyValues'][0]);
        $this->assertSame($bill->fresh()->issued_at->translatedFormat('F Y'), $sent['bodyValues'][1]);
        $this->assertSame('650.000', $sent['bodyValues'][2]);
        $this->assertSame('VA Bank Muamalat', $sent['bodyValues'][4]);
        // No. Referensi is the VA - the number e-SPP's list and the bank show.
        $this->assertSame('8020011234567890', $sent['bodyValues'][5]);

        $log = NotificationLog::where('channel', 'whatsapp')->where('template', 'receipt_spp_school')->first();
        $this->assertNotNull($log);
        $this->assertSame('queued', $log->status);
        $this->assertSame($bill->id, $log->notifiable_id);
    }

    public function test_a_payment_covering_several_consecutive_months_sends_one_consolidated_receipt(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $student = $this->studentNamed('Fulan Abdurrahman');
        $august = $this->billFor($student, 'SPP Agustus 2026', 650000, Carbon::create(2026, 8, 15));
        $september = $this->billFor($student, 'SPP September 2026', 650000, Carbon::create(2026, 9, 15));
        $october = $this->billFor($student, 'SPP Oktober 2026', 650000, Carbon::create(2026, 10, 15));

        $payment = Payment::create([
            'payment_number' => 'PAY/20260822/GHIJKL',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 1950000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
            'gateway_response' => ['bank_name' => 'Bank Syariah Indonesia (BSI)', 'va_number' => '3656011234567890'],
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $august->id, 'amount' => 650000]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $september->id, 'amount' => 650000]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $october->id, 'amount' => 650000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_2');

        // One WhatsApp message for the whole payment, not three - a parent
        // catching up on three months of SPP at once should not get three
        // back-to-back notifications for what was, to them, one payment.
        $this->assertCount(1, $this->sentQontakTemplates);
        $this->assertSame(1, NotificationLog::where('template', 'receipt_spp_school')->count());

        $sent = $this->sentQontakTemplates[0];
        $this->assertSame('Agustus - Oktober 2026', $sent['bodyValues'][1]);
        $this->assertSame('1.950.000', $sent['bodyValues'][2]);

        $log = NotificationLog::where('template', 'receipt_spp_school')->first();
        $this->assertSame([$august->id, $september->id, $october->id], $log->payload['bill_ids']);
    }

    public function test_a_single_month_payment_shows_just_that_month_not_a_range(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $student = $this->studentNamed('Satu Bulan Saja');
        $bill = $this->billFor($student, 'SPP September 2026', 650000, Carbon::create(2026, 9, 15));

        $payment = Payment::create([
            'payment_number' => 'PAY/20260826/EFGHIJ',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
            'gateway_response' => ['bank_name' => 'Bank Muamalat'],
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 650000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_6');

        $this->assertCount(1, $this->sentQontakTemplates);
        $this->assertSame('September 2026', $this->sentQontakTemplates[0]['bodyValues'][1]);
    }

    public function test_non_consecutive_months_are_listed_instead_of_ranged(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $student = $this->studentNamed('Bayar Loncat Bulan');
        $august = $this->billFor($student, 'SPP Agustus 2026', 650000, Carbon::create(2026, 8, 15));
        // September is deliberately skipped - a gap, not a range.
        $november = $this->billFor($student, 'SPP November 2026', 650000, Carbon::create(2026, 11, 15));

        $payment = Payment::create([
            'payment_number' => 'PAY/20260827/KLMNOP',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 1300000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $august->id, 'amount' => 650000]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $november->id, 'amount' => 650000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_7');

        $this->assertSame('Agustus 2026, November 2026', $this->sentQontakTemplates[0]['bodyValues'][1]);
    }

    public function test_a_non_spp_bill_gets_no_receipt(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $uangPangkal = FeeType::create(['code' => 'uang_pangkal', 'name' => 'Uang Pangkal', 'recurrence' => 'once']);

        $student = $this->studentNamed('Zainal Arifin');
        $bill = Bill::create([
            'bill_number' => 'UP/'.uniqid(),
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $uangPangkal->id,
            'dedup_key' => 'uang_pangkal:'.uniqid(),
            'description' => 'Uang Pangkal',
            'subtotal' => 5000000,
            'discount_amount' => 0,
            'late_fee' => 0,
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'remaining_amount' => 5000000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
            'allow_installment' => false,
            'issued_at' => now(),
        ]);
        $student->guardians()->attach($this->guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $payment = Payment::create([
            'payment_number' => 'PAY/20260823/MNOPQR',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 5000000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 5000000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_3');

        $this->assertEmpty($this->sentQontakTemplates);
        $this->assertSame('paid', $bill->fresh()->status);
    }

    public function test_settling_is_never_blocked_by_a_missing_template_id(): void
    {
        config(['services.qontak.spp_receipt_template_id' => null]);

        $student = $this->studentNamed('Keinnara Adzkia');
        $bill = $this->billFor($student);

        $payment = Payment::create([
            'payment_number' => 'PAY/20260824/STUVWX',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 650000]);

        app(PaymentAllocator::class)->settle($payment, 'tx_test_4');

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertEmpty($this->sentQontakTemplates);
        $this->assertSame(0, NotificationLog::where('template', 'receipt_spp_school')->count());
    }

    public function test_settling_twice_never_sends_a_second_receipt(): void
    {
        config(['services.qontak.spp_receipt_template_id' => 'receipt-template-uuid']);

        $student = $this->studentNamed('Ahmad Fauzan');
        $bill = $this->billFor($student);

        $payment = Payment::create([
            'payment_number' => 'PAY/20260825/YZABCD',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 650000,
            'method' => 'virtual_account',
            'channel' => 'billing_api',
            'status' => 'pending',
        ]);

        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 650000]);

        $allocator = app(PaymentAllocator::class);
        $allocator->settle($payment, 'tx_test_5');
        $allocator->settle($payment->fresh(), 'tx_test_5');

        // settle() itself refuses anything not still pending/processing -
        // the second call is a no-op, so the receipt must not double either.
        $this->assertCount(1, $this->sentQontakTemplates);
    }
}
