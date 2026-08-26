<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendagoPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_secret_123';
    private SchoolUnit $unit;
    private Student $student;
    private Guardian $guardian;
    private Bill $bill;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sendagopay.webhook_secret' => $this->secret]);

        $this->unit = SchoolUnit::create([
            'code' => 'SD-ALAZHAR13',
            'label' => 'SD Islam Al Azhar 13',
            'jenjang_group' => 'sd',
        ]);

        $user = User::create([
            'name' => 'Wali Murid',
            'email' => 'wali@example.com',
            'phone' => '081234567890',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $this->guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Wali Murid',
            'hubungan' => 'ayah',
            'no_hp' => '081234567890',
            'email' => 'wali@example.com',
        ]);

        $this->student = Student::create([
            'school_unit_id' => $this->unit->id,
            'nis' => '12345',
            'nisn' => '0012345678',
            'nik' => '3201010101010001',
            'nama_lengkap' => 'Ahmad Fulan',
            'nama_panggilan' => 'Ahmad',
            'jenis_kelamin' => 'L',
            'status' => 'active',
        ]);

        $year = AcademicYear::create([
            'year' => '2026/2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
        ]);

        $feeType = \App\Models\FeeType::create([
            'code' => 'spp',
            'name' => 'SPP',
            'recurrence' => 'monthly',
        ]);

        $this->bill = Bill::create([
            'student_id' => $this->student->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $feeType->id,
            'bill_number' => 'BILL/202608/0001',
            'dedup_key' => 'spp:2026:08',
            'description' => 'SPP Agustus 2026',
            'subtotal' => 500000,
            'discount_amount' => 0,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'remaining_amount' => 500000,
            'due_date' => now()->addDays(10),
            'issued_at' => now(),
            'status' => 'unpaid',
        ]);

        $this->payment = Payment::create([
            'payment_number' => 'PAY/20260820/ABCDEF',
            'payer_guardian_id' => $this->guardian->id,
            'amount' => 500000,
            'method' => 'qris',
            'channel' => 'qris',
            'status' => 'pending',
            'external_transaction_id' => 'tx_sendagopay_987654',
        ]);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'bill_id' => $this->bill->id,
            'amount' => 500000,
        ]);
    }

    public function test_rejects_webhook_with_invalid_signature(): void
    {
        $payload = [
            'event' => 'payment.success',
            'transaction_id' => 'tx_sendagopay_987654',
            'order_id' => $this->payment->payment_number,
            'amount' => 500000,
            'status' => 'PAID',
        ];

        $response = $this->postJson('/api/webhooks/sendagopay', $payload, [
            'X-Sendago-Signature' => 'invalid_signature_hash',
            'X-Sendago-Event' => 'payment.success',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('pending', $this->payment->fresh()->status);
    }

    public function test_rejects_webhook_when_no_secret_is_configured(): void
    {
        // payment_number isn't a secret - it's shown to the family and
        // printed on-screen as the bank-transfer reference. An unset secret
        // must never mean "skip verification"; that would let anyone forge
        // a payment.success for a payment_number they've simply seen.
        config(['services.sendagopay.webhook_secret' => null]);

        $payload = [
            'event' => 'payment.success',
            'transaction_id' => 'tx_sendagopay_987654',
            'order_id' => $this->payment->payment_number,
            'amount' => 500000,
            'status' => 'PAID',
        ];

        $response = $this->postJson('/api/webhooks/sendagopay', $payload, [
            'X-Sendago-Signature' => 'anything',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('pending', $this->payment->fresh()->status);
    }

    public function test_successfully_settles_payment_on_sendagopay_webhook(): void
    {
        $payload = [
            'event' => 'payment.success',
            'transaction_id' => 'tx_sendagopay_987654',
            'order_id' => $this->payment->payment_number,
            'amount' => 500000,
            'unique_code' => 0,
            'total_amount' => 500000,
            'status' => 'PAID',
            'channel' => 'QRIS',
            'timestamp' => time(),
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secret);

        $response = $this->call(
            'POST',
            '/api/webhooks/sendagopay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SENDAGO_SIGNATURE' => $signature,
                'HTTP_X_SENDAGO_EVENT' => 'payment.success',
            ],
            $payloadJson
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        $this->assertEquals('completed', $this->payment->fresh()->status);
        $this->assertEquals('paid', $this->bill->fresh()->status);
        $this->assertEquals(500000, (float) $this->bill->fresh()->paid_amount);
        $this->assertEquals(0, (float) $this->bill->fresh()->remaining_amount);
    }

    public function test_idempotent_duplicate_delivery(): void
    {
        $payload = [
            'event' => 'payment.success',
            'transaction_id' => 'tx_sendagopay_987654',
            'order_id' => $this->payment->payment_number,
            'amount' => 500000,
            'status' => 'PAID',
            'channel' => 'QRIS',
            'timestamp' => time(),
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secret);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SENDAGO_SIGNATURE' => $signature,
            'HTTP_X_SENDAGO_EVENT' => 'payment.success',
        ];

        // First delivery
        $res1 = $this->call('POST', '/api/webhooks/sendagopay', [], [], [], $headers, $payloadJson);
        $res1->assertStatus(200)->assertJson(['status' => 'ok']);

        // Duplicate delivery
        $res2 = $this->call('POST', '/api/webhooks/sendagopay', [], [], [], $headers, $payloadJson);
        $res2->assertStatus(200)->assertJson(['status' => 'duplicate']);

        $this->assertEquals('completed', $this->payment->fresh()->status);
    }

    public function test_handles_expired_payment_event(): void
    {
        $payload = [
            'event' => 'payment.expired',
            'transaction_id' => 'tx_sendagopay_987654',
            'order_id' => $this->payment->payment_number,
            'status' => 'EXPIRED',
            'timestamp' => time(),
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secret);

        $response = $this->call(
            'POST',
            '/api/webhooks/sendagopay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SENDAGO_SIGNATURE' => $signature,
                'HTTP_X_SENDAGO_EVENT' => 'payment.expired',
            ],
            $payloadJson
        );

        $response->assertStatus(200)->assertJson(['status' => 'ok']);
        $this->assertEquals('expired', $this->payment->fresh()->status);
        $this->assertEquals('unpaid', $this->bill->fresh()->status);
    }
}
