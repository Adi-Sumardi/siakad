<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Payment\SendagoPayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendagoPayGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_invoice_on_sendagopay_successfully(): void
    {
        config([
            'services.sendagopay.secret_key' => 'sg_live_sk_test123',
            'services.sendagopay.base_url' => 'https://api-sendagopay.adilabs.id',
        ]);

        $unit = SchoolUnit::create([
            'code' => 'SD-ALAZHAR13',
            'label' => 'SD Islam Al Azhar 13',
            'jenjang_group' => 'sd',
        ]);

        $user = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'role' => 'orangtua',
            'is_active' => true,
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Budi Santoso',
            'hubungan' => 'ayah',
            'no_hp' => '081234567890',
            'email' => 'budi@example.com',
        ]);

        $student = Student::create([
            'school_unit_id' => $unit->id,
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

        $feeType = FeeType::create([
            'code' => 'spp',
            'name' => 'SPP',
            'recurrence' => 'monthly',
        ]);

        $bill = Bill::create([
            'student_id' => $student->id,
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

        $payment = Payment::create([
            'payment_number' => 'PAY/20260820/ABCDEF',
            'payer_guardian_id' => $guardian->id,
            'amount' => 500000,
            'method' => 'qris',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api-sendagopay.adilabs.id/v1/payments' => Http::response([
                'id' => 'tx-uuid-123456',
                'order_id' => 'PAY/20260820/ABCDEF',
                'amount' => 500000,
                'unique_code' => 142,
                'total_amount' => 500142,
                'checkout_url' => 'https://sendagopay.adilabs.id/pay/tx-uuid-123456',
                'qris_payload' => '00020101021226530014ID.LINKAJA.WWW011893600911002200889802150000000000000000303UME51440014ID.CO.QRIS.WWW0215ID10200210000020303UME52045812530336054065001425802ID5913YAPI SEKOLAH6007JAKARTA61051234062070703A016304726B',
                'status' => 'PENDING',
                'expired_at' => now()->addMinutes(120)->toISOString(),
            ], 201),
        ]);

        $gateway = new SendagoPayGateway();
        $result = $gateway->createInvoice($payment, collect([$bill]), $guardian);

        $this->assertEquals('processing', $result->status);
        $this->assertEquals('tx-uuid-123456', $result->invoice_id);
        $this->assertEquals('https://sendagopay.adilabs.id/pay/tx-uuid-123456', $result->invoice_url);
    }
}
