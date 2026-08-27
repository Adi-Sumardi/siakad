<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\BillingApiGateway;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BillingApiVirtualAccountTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $tkUnit;
    private SchoolUnit $sdUnit;
    private SchoolUnit $smp12Unit;
    private SchoolUnit $smp55Unit;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->year = AcademicYear::create([
            'year' => '2026/2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->tkUnit = SchoolUnit::create([
            'code' => 'TK-13',
            'label' => 'TK Islam Al Azhar 13',
            'jenjang_group' => 'tk',
            'is_active' => true,
        ]);

        $this->sdUnit = SchoolUnit::create([
            'code' => 'SD-13',
            'label' => 'SD Islam Al Azhar 13 Rawamangun',
            'jenjang_group' => 'sd',
            'is_active' => true,
        ]);

        $this->smp12Unit = SchoolUnit::create([
            'code' => 'SMP-12',
            'label' => 'SMP Islam Al Azhar 12 Rawamangun',
            'jenjang_group' => 'smp',
            'is_active' => true,
        ]);

        $this->smp55Unit = SchoolUnit::create([
            'code' => 'SMP-55',
            'label' => 'SMP Islam Al Azhar 55 Jatiasih',
            'jenjang_group' => 'smp',
            'is_active' => true,
        ]);
    }

    public function test_it_generates_correct_16_digit_va_for_spp_and_jamiyyah(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Ahmad Dahlan',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '001234',
        ]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $jamiyyahType = FeeType::create(['code' => 'jamiyyah', 'name' => 'Uang Jamiyyah', 'recurrence' => 'per_term']);

        $sppBill = Bill::create([
            'bill_number' => 'SPP/2026/08/00001',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 500000,
            'total_amount' => 500000,
            'remaining_amount' => 500000,
            'status' => 'unpaid',
            'due_date' => '2026-08-31',
            'issued_at' => now(),
        ]);

        $jamiyyahBill = Bill::create([
            'bill_number' => 'JAM/2026/08/00001',
            'dedup_key' => 'jam:2026:08:'.$student->id,
            'description' => 'Uang Jamiyyah 2026/2027',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $jamiyyahType->id,
            'subtotal' => 100000,
            'total_amount' => 100000,
            'remaining_amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-08-31',
            'issued_at' => now(),
        ]);

        // SPP: 802001 + 2627 + 001234 = 8020012627001234 (16 digits)
        $sppVa = BillingApiClient::generateVaNumber($student, $sppBill);
        $this->assertEquals('8020012627001234', $sppVa);
        $this->assertEquals(16, strlen($sppVa));

        // Jamiyyah: 802003 + 2627 + 001234 = 8020032627001234 (16 digits)
        $jamiyyahVa = BillingApiClient::generateVaNumber($student, $jamiyyahBill);
        $this->assertEquals('8020032627001234', $jamiyyahVa);
        $this->assertEquals(16, strlen($jamiyyahVa));
    }

    public function test_it_generates_unit_specific_va_prefixes_for_ekskul(): void
    {
        $ekskulType = FeeType::create(['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term']);

        // 1. TK Unit -> 802005
        $studentTk = Student::create([
            'nama_lengkap' => 'Ananda TK',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->tkUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '100',
        ]);
        $vaTk = BillingApiClient::generateVaNumber($studentTk, $ekskulType);
        $this->assertEquals('8020052627000100', $vaTk);

        // 2. SD Unit -> 802006
        $studentSd = Student::create([
            'nama_lengkap' => 'Ananda SD',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '200',
        ]);
        $vaSd = BillingApiClient::generateVaNumber($studentSd, $ekskulType);
        $this->assertEquals('8020062627000200', $vaSd);

        // 3. SMP-12 Unit -> 802007
        $studentSmp12 = Student::create([
            'nama_lengkap' => 'Ananda SMP 12',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp12Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '300',
        ]);
        $vaSmp12 = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType);
        $this->assertEquals('8020072627000300', $vaSmp12);

        // 4. SMP-55 Unit -> 802008
        $studentSmp55 = Student::create([
            'nama_lengkap' => 'Ananda SMP 55',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp55Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '400',
        ]);
        $vaSmp55 = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType);
        $this->assertEquals('8020082627000400', $vaSmp55);
    }

    public function test_it_formats_student_code_from_various_sources(): void
    {
        // 1. From NIS
        $s1 = Student::create(['nama_lengkap' => 'S1', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '12345']);
        $this->assertEquals('012345', BillingApiClient::formatStudentCode($s1));

        // 2. From PMB No Pendaftaran (Option A Month+Seq: PMB08260005 -> 080005)
        $s2 = Student::create(['nama_lengkap' => 'S2', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'no_pendaftaran' => 'PMB08260005']);
        $this->assertEquals('080005', BillingApiClient::formatStudentCode($s2));

        // 3. From PMB Continuous Seq (PMB000777 -> 000777)
        $s3 = Student::create(['nama_lengkap' => 'S3', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'no_pendaftaran' => 'PMB000777']);
        $this->assertEquals('000777', BillingApiClient::formatStudentCode($s3));
    }

    public function test_it_sanitizes_customer_names(): void
    {
        $clean = BillingApiClient::sanitizeCustomerName('Muhammad "Fulan" & H. Ali (Anak #1) / TK');
        $this->assertLessThanOrEqual(30, strlen($clean));
        $this->assertStringNotContainsString('"', $clean);
        $this->assertStringNotContainsString('&', $clean);
        $this->assertStringNotContainsString('#', $clean);
        $this->assertStringNotContainsString('/', $clean);
    }

    public function test_billing_api_gateway_creates_va_successfully(): void
    {
        $user = User::create([
            'name' => 'Wali Murid',
            'role' => 'orangtua',
            'phone' => '081292702075',
            'email' => 'wali@example.com',
            'is_active' => true,
        ]);
        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Wali Murid',
            'hubungan' => 'ibu',
            'no_hp' => '081292702075',
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Siti Aisyah',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '500',
        ]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00002',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 650000,
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => '2026-08-31',
            'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->once()
            ->andReturn([
                'uuid' => 'bill-uuid-test-123',
                'status' => 'success',
            ]);

        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);
        $response = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
        ]);

        $response->assertStatus(201);
        $paymentData = $response->json('payment');

        $this->assertEquals('8020012627000500', $paymentData['virtual_account']['va_number']);
        $this->assertEquals('Bank Muamalat', $paymentData['virtual_account']['bank_name']);
        $this->assertEquals(650000, $paymentData['amount']);
        $this->assertEquals('processing', $paymentData['status']);
    }

    public function test_billing_api_webhook_settles_payment(): void
    {
        $user = User::create(['name' => 'Wali', 'role' => 'orangtua', 'phone' => '081292702075', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Budi', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '600']);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00003',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 700000,
            'total_amount' => 700000,
            'remaining_amount' => 700000,
            'status' => 'unpaid',
            'due_date' => '2026-08-31',
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_number' => 'YAPI-SPP-2026-000600',
            'payer_guardian_id' => $guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'external_transaction_id' => 'bill-uuid-600',
            'invoice_id' => 'bill-uuid-600',
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '8020012627000600',
                'billing_uuid' => 'bill-uuid-600',
            ],
        ]);

        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('8020012627000600')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->postJson('/api/payment-webhook/trans-uuid-600', [
            'jumlah_pembayaran' => 700000,
            'uuid' => 'trans-uuid-600',
            'billing_uuid' => 'bill-uuid-600',
            'customer_name' => 'Budi',
            'payment_type' => 'PAYMENT',
            'jumlah_tagihan' => 700000,
            'reference_no' => '8020012627000600',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals('paid', $bill->fresh()->status);
        $this->assertEquals(0, (float) $bill->fresh()->remaining_amount);
    }

    /**
     * e-SPP's callback carries no signature to check - this live lookup
     * against e-SPP's own record of the VA is the only thing standing
     * between "a POST arrived claiming this paid" and settling money. If
     * e-SPP can't be reached to confirm it, the payment must be left alone,
     * not settled on the strength of the claim alone - a genuinely-settled
     * payment isn't lost either way, since payments:poll-billing-va performs
     * the same live check independently on a schedule and will catch it.
     */
    public function test_billing_api_webhook_does_not_settle_when_esp_cannot_be_reached(): void
    {
        $user = User::create(['name' => 'Wali', 'role' => 'orangtua', 'phone' => '081292702076', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Citra', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '601']);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00004',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 700000,
            'total_amount' => 700000,
            'remaining_amount' => 700000,
            'status' => 'unpaid',
            'due_date' => '2026-08-31',
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_number' => 'YAPI-SPP-2026-000601',
            'payer_guardian_id' => $guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'external_transaction_id' => 'bill-uuid-601',
            'invoice_id' => 'bill-uuid-601',
            'gateway_response' => [
                'provider' => 'bank_muamalat',
                'va_number' => '8020012627000601',
                'billing_uuid' => 'bill-uuid-601',
            ],
        ]);

        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('8020012627000601')
            ->andThrow(new \App\Services\Billing\BillingApiException('e-SPP unreachable', 500));
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->postJson('/api/payment-webhook/trans-uuid-601', [
            'jumlah_pembayaran' => 700000,
            'uuid' => 'trans-uuid-601',
            'billing_uuid' => 'bill-uuid-601',
            'reference_no' => '8020012627000601',
        ]);

        $response->assertOk();
        $this->assertEquals('processing', $payment->fresh()->status);
        $this->assertEquals('unpaid', $bill->fresh()->status);
    }
}
