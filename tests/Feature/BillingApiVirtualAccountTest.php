<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
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
        $studentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);

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
            'due_date' => now()->addDays(7)->toDateString(),
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
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        // SPP Muamalat: 802001 + 2627 + student id
        $sppVa = BillingApiClient::generateVaNumber($student, $sppBill, 'muamalat');
        $this->assertEquals('802001'.'2627'.$studentCode, $sppVa);
        $this->assertEquals(16, strlen($sppVa));

        // Jamiyyah Muamalat: 802003 + 2627 + student id
        $jamiyyahVa = BillingApiClient::generateVaNumber($student, $jamiyyahBill, 'muamalat');
        $this->assertEquals('802003'.'2627'.$studentCode, $jamiyyahVa);
        $this->assertEquals(16, strlen($jamiyyahVa));

        // SPP BSI: 789501 + 2627 + student id
        $sppVaBsi = BillingApiClient::generateVaNumber($student, $sppBill, 'bsi');
        $this->assertEquals('789501'.'2627'.$studentCode, $sppVaBsi);
        $this->assertEquals(16, strlen($sppVaBsi));

        // Jamiyyah BSI: 789503 + 2627 + student id
        $jamiyyahVaBsi = BillingApiClient::generateVaNumber($student, $jamiyyahBill, 'bsi');
        $this->assertEquals('789503'.'2627'.$studentCode, $jamiyyahVaBsi);
        $this->assertEquals(16, strlen($jamiyyahVaBsi));
    }

    public function test_it_generates_unit_specific_va_prefixes_for_ekskul(): void
    {
        $ekskulType = FeeType::create(['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term']);

        // 1. TK Unit -> 802005 (Muamalat) & 789505 (BSI)
        $studentTk = Student::create([
            'nama_lengkap' => 'Ananda TK',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->tkUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '100',
        ]);
        $vaTk = BillingApiClient::generateVaNumber($studentTk, $ekskulType, 'muamalat');
        $this->assertEquals('802005'.'2627'.str_pad((string) $studentTk->id, 6, '0', STR_PAD_LEFT), $vaTk);
        $vaTkBsi = BillingApiClient::generateVaNumber($studentTk, $ekskulType, 'bsi');
        $this->assertEquals('789505'.'2627'.str_pad((string) $studentTk->id, 6, '0', STR_PAD_LEFT), $vaTkBsi);

        // 2. SD Unit -> 802006 (Muamalat) & 789506 (BSI)
        $studentSd = Student::create([
            'nama_lengkap' => 'Ananda SD',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '200',
        ]);
        $vaSd = BillingApiClient::generateVaNumber($studentSd, $ekskulType, 'muamalat');
        $this->assertEquals('802006'.'2627'.str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSd);
        $vaSdBsi = BillingApiClient::generateVaNumber($studentSd, $ekskulType, 'bsi');
        $this->assertEquals('789506'.'2627'.str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSdBsi);

        // 3. SMP-12 Unit -> 802007 (Muamalat) & 789507 (BSI)
        $studentSmp12 = Student::create([
            'nama_lengkap' => 'Ananda SMP 12',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp12Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '300',
        ]);
        $vaSmp12 = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType, 'muamalat');
        $this->assertEquals('802007'.'2627'.str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12);
        $vaSmp12Bsi = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType, 'bsi');
        $this->assertEquals('789507'.'2627'.str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12Bsi);

        // 4. SMP-55 Unit -> 802008 (Muamalat) & 789508 (BSI)
        $studentSmp55 = Student::create([
            'nama_lengkap' => 'Ananda SMP 55',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp55Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '400',
        ]);
        $vaSmp55 = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType, 'muamalat');
        $this->assertEquals('802008'.'2627'.str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55);
        $vaSmp55Bsi = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType, 'bsi');
        $this->assertEquals('789508'.'2627'.str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55Bsi);
    }

    /**
     * Cambridge runs on its own per-unit prefixes (school decision
     * 2026-09-22): SD 09, SMP-12 10, SMP-55 11 after ekskul's 05-08 - and,
     * unlike ekskul, no prefix at all for units outside the program.
     */
    public function test_it_generates_unit_specific_va_prefixes_for_cambridge(): void
    {
        $cambridgeType = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        // SD Unit -> 802009 (Muamalat) & 789509 (BSI)
        $studentSd = Student::create([
            'nama_lengkap' => 'Cambridge SD',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '900',
        ]);
        $vaSd = BillingApiClient::generateVaNumber($studentSd, $cambridgeType, 'muamalat');
        $this->assertEquals('802009'.'2627'.str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSd);
        $this->assertEquals(16, strlen($vaSd));
        $vaSdBsi = BillingApiClient::generateVaNumber($studentSd, $cambridgeType, 'bsi');
        $this->assertEquals('789509'.'2627'.str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSdBsi);

        // SMP-12 Unit -> 802010 (Muamalat) & 789510 (BSI)
        $studentSmp12 = Student::create([
            'nama_lengkap' => 'Cambridge SMP 12',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp12Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '901',
        ]);
        $vaSmp12 = BillingApiClient::generateVaNumber($studentSmp12, $cambridgeType, 'muamalat');
        $this->assertEquals('802010'.'2627'.str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12);
        $vaSmp12Bsi = BillingApiClient::generateVaNumber($studentSmp12, $cambridgeType, 'bsi');
        $this->assertEquals('789510'.'2627'.str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12Bsi);

        // SMP-55 Unit -> 802011 (Muamalat) & 789511 (BSI)
        $studentSmp55 = Student::create([
            'nama_lengkap' => 'Cambridge SMP 55',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp55Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '902',
        ]);
        $vaSmp55 = BillingApiClient::generateVaNumber($studentSmp55, $cambridgeType, 'muamalat');
        $this->assertEquals('802011'.'2627'.str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55);
        $vaSmp55Bsi = BillingApiClient::generateVaNumber($studentSmp55, $cambridgeType, 'bsi');
        $this->assertEquals('789511'.'2627'.str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55Bsi);
    }

    public function test_cambridge_has_no_prefix_outside_the_participating_units(): void
    {
        // TK/RA/PG/SMA are not in the Cambridge program - null, never a
        // borrowed SD prefix, so no student there can ever mint a Cambridge VA.
        $this->assertNull(BillingApiClient::resolvePrefix('cambridge', $this->tkUnit));

        $raUnit = SchoolUnit::create(['code' => 'RA-SAKINAH', 'label' => 'RA Al Azhar Sakinah', 'jenjang_group' => 'ra', 'is_active' => true]);
        $smaUnit = SchoolUnit::create(['code' => 'SMA-33', 'label' => 'SMA Islam Al Azhar 33', 'jenjang_group' => 'sma', 'is_active' => true]);
        $this->assertNull(BillingApiClient::resolvePrefix('cambridge', $raUnit));
        $this->assertNull(BillingApiClient::resolvePrefix('cambridge', $smaUnit));

        // Without a unit the SD prefix stands in - the catalogue listing only
        // asks "can this type mint a VA at all", never for one student.
        $this->assertSame('802009', BillingApiClient::resolvePrefix('cambridge'));
        $this->assertSame('789509', BillingApiClient::resolvePrefix('cambridge', null, 'bsi'));

        $studentTk = Student::create([
            'nama_lengkap' => 'Cambridge TK',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->tkUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '903',
        ]);
        $cambridgeType = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);

        $this->expectException(BillingApiException::class);
        BillingApiClient::generateVaNumber($studentTk, $cambridgeType);
    }

    public function test_a_cambridge_bill_checks_out_on_its_own_sd_va(): void
    {
        $user = User::create(['name' => 'Wali Cambridge', 'role' => 'orangtua', 'phone' => '081292702090', 'email' => 'walicambridge@example.com', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Cambridge', 'hubungan' => 'ayah', 'no_hp' => '081292702090']);

        $student = Student::create([
            'nama_lengkap' => 'Anak Cambridge',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '904',
        ]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $cambridgeType = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once']);
        $bill = Bill::create([
            'bill_number' => 'CAM/2026/00001',
            'dedup_key' => 'cambridge:2026-2027:'.$student->id,
            'description' => 'Cambridge TA 2026/2027',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $cambridgeType->id,
            'subtotal' => 500000,
            'total_amount' => 500000,
            'remaining_amount' => 500000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->once()
            ->andReturn(['uuid' => 'bill-uuid-cambridge', 'status' => 'success']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->actingAs($user)->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
            'bank' => 'muamalat',
        ]);

        $response->assertStatus(201);
        $expectedStudentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $this->assertEquals('8020092627'.$expectedStudentCode, $response->json('payment.virtual_account.va_number'));
    }

    public function test_it_formats_student_code_from_the_students_own_id_not_nis(): void
    {
        $s1 = Student::create(['nama_lengkap' => 'S1', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '1000027001']);
        $s2 = Student::create(['nama_lengkap' => 'S2', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '2000027001']);

        $this->assertEquals(str_pad((string) $s1->id, 6, '0', STR_PAD_LEFT), BillingApiClient::formatStudentCode($s1));
        $this->assertEquals(str_pad((string) $s2->id, 6, '0', STR_PAD_LEFT), BillingApiClient::formatStudentCode($s2));
        $this->assertNotEquals(BillingApiClient::formatStudentCode($s1), BillingApiClient::formatStudentCode($s2));

        $s3 = Student::create(['nama_lengkap' => 'S3', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'no_pendaftaran' => 'PMB000777']);
        $this->assertEquals(str_pad((string) $s3->id, 6, '0', STR_PAD_LEFT), BillingApiClient::formatStudentCode($s3));
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
            'due_date' => now()->addDays(7)->toDateString(),
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
            'bank' => 'muamalat',
        ]);

        $response->assertStatus(201);
        $paymentData = $response->json('payment');

        $expectedStudentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $this->assertEquals('8020012627'.$expectedStudentCode, $paymentData['virtual_account']['va_number']);
        $this->assertEquals('Bank Muamalat', $paymentData['virtual_account']['bank_name']);
        $this->assertEquals('147', $paymentData['virtual_account']['bank_code']);
        $this->assertEquals(650000, $paymentData['amount']);
        $this->assertEquals('processing', $paymentData['status']);
    }

    public function test_billing_api_gateway_creates_bsi_va_when_selected(): void
    {
        $user = User::create([
            'name' => 'Wali BSI',
            'role' => 'orangtua',
            'phone' => '081292702088',
            'email' => 'walibsi@example.com',
            'is_active' => true,
        ]);
        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Wali BSI',
            'hubungan' => 'ayah',
            'no_hp' => '081292702088',
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Fathan BSI',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '555',
        ]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00005',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 650000,
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->once()
            ->andReturn([
                'uuid' => 'bill-uuid-test-bsi',
                'status' => 'success',
            ]);

        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);
        $response = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
            'bank' => 'bsi',
        ]);

        $response->assertStatus(201);
        $paymentData = $response->json('payment');

        $expectedStudentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $this->assertEquals('7895012627'.$expectedStudentCode, $paymentData['virtual_account']['va_number']);
        $this->assertEquals('Bank Syariah Indonesia (BSI)', $paymentData['virtual_account']['bank_name']);
        $this->assertEquals('451', $paymentData['virtual_account']['bank_code']);
        $this->assertEquals(650000, $paymentData['amount']);
        $this->assertEquals('processing', $paymentData['status']);
    }

    public function test_checkout_refuses_a_basket_spanning_two_children_under_the_va_gateway(): void
    {
        $user = User::create(['name' => 'Wali Dua Anak', 'role' => 'orangtua', 'phone' => '081292702077', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Dua Anak', 'hubungan' => 'ayah']);

        $studentA = Student::create(['nama_lengkap' => 'Anak Satu', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '700']);
        $studentB = Student::create(['nama_lengkap' => 'Anak Dua', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->smp12Unit->id, 'entry_year_id' => $this->year->id, 'nis' => '701']);
        foreach ([$studentA, $studentB] as $s) {
            $s->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);
        }

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $billA = Bill::create([
            'bill_number' => 'SPP/2026/08/00010', 'dedup_key' => 'spp:2026:08:'.$studentA->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $studentA->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);
        $billB = Bill::create([
            'bill_number' => 'SPP/2026/08/00011', 'dedup_key' => 'spp:2026:08:'.$studentB->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $studentB->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 750000, 'total_amount' => 750000, 'remaining_amount' => 750000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldNotReceive('createBilling');
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->actingAs($user)->postJson('/api/wali/checkout', [
            'bill_ulids' => [$billA->ulid, $billB->ulid],
            'method' => 'virtual_account',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_later_checkout_for_the_same_child_and_fee_type_supersedes_the_earlier_one(): void
    {
        $user = User::create(['name' => 'Wali Bulanan', 'role' => 'orangtua', 'phone' => '081292702078', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Bulanan', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Anak Bulanan', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '702']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $julyBill = Bill::create([
            'bill_number' => 'SPP/2026/07/00020', 'dedup_key' => 'spp:2026:07:'.$student->id,
            'description' => 'SPP Juli 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);
        $augustBill = Bill::create([
            'bill_number' => 'SPP/2026/08/00021', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->twice()
            ->andReturn(['uuid' => 'bill-uuid-1'], ['uuid' => 'bill-uuid-2']);
        // The supersede guard asks the bank about the older VA before closing
        // it - still outstanding here, so superseding is safe.
        $mockClient->shouldReceive('getByVaNumber')
            ->once()
            ->andReturn(['sisa' => 650000]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);

        $first = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$julyBill->ulid],
            'method' => 'virtual_account',
        ])->json('payment');

        $this->assertEquals('processing', Payment::where('ulid', $first['ulid'])->value('status'));

        $second = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$augustBill->ulid],
            'method' => 'virtual_account',
        ])->json('payment');

        $this->assertEquals('failed', Payment::where('ulid', $first['ulid'])->value('status'));
        $this->assertEquals('processing', Payment::where('ulid', $second['ulid'])->value('status'));
        $this->assertSame('unpaid', $julyBill->fresh()->status);
    }

    public function test_a_superseded_va_the_bank_says_was_paid_gets_settled_not_buried(): void
    {
        $user = User::create(['name' => 'Wali Telat Bayar', 'role' => 'orangtua', 'phone' => '081292702079', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Telat Bayar', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Anak Telat Bayar', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '703']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $julyBill = Bill::create([
            'bill_number' => 'SPP/2026/07/00030', 'dedup_key' => 'spp:2026:07:'.$student->id,
            'description' => 'SPP Juli 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);
        $augustBill = Bill::create([
            'bill_number' => 'SPP/2026/08/00031', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->once()->andReturn(['uuid' => 'bill-uuid-early']);
        // The bank's word on the older VA: fully paid. The money exists - it
        // must be booked under the payment that earned it, and the new
        // checkout must stop rather than strand it.
        $mockClient->shouldReceive('getByVaNumber')->once()->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);

        $first = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$julyBill->ulid],
            'method' => 'virtual_account',
        ])->json('payment');

        $second = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$augustBill->ulid],
            'method' => 'virtual_account',
        ]);

        $second->assertStatus(422);
        $this->assertEquals('completed', Payment::where('ulid', $first['ulid'])->value('status'));
        $this->assertSame('paid', $julyBill->fresh()->status);
        $this->assertSame('unpaid', $augustBill->fresh()->status);
    }

    public function test_paying_several_months_of_spp_at_once_uses_one_consistent_va(): void
    {
        foreach ([3, 5, 10] as $monthCount) {
            $user = User::create(['name' => "Wali {$monthCount} bulan", 'role' => 'orangtua', 'phone' => "08129270{$monthCount}000", 'is_active' => true]);
            $guardian = Guardian::create(['user_id' => $user->id, 'nama' => "Wali {$monthCount} bulan", 'hubungan' => 'ayah']);
            $student = Student::create(['nama_lengkap' => "Anak {$monthCount} Bulan", 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => (string) (800 + $monthCount)]);
            $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

            $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
            $expectedVa = BillingApiClient::generateVaNumber($student, $sppType, 'muamalat');

            $billUlids = [];
            $totalExpected = 0;
            for ($m = 1; $m <= $monthCount; $m++) {
                $bill = Bill::create([
                    'bill_number' => sprintf('SPP/2026/%02d/%05d', $m, $student->id),
                    'dedup_key' => "spp:2026:{$m}:{$student->id}",
                    'description' => "SPP Bulan {$m} 2026",
                    'student_id' => $student->id,
                    'academic_year_id' => $this->year->id,
                    'fee_type_id' => $sppType->id,
                    'subtotal' => 650000,
                    'total_amount' => 650000,
                    'remaining_amount' => 650000,
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(10)->toDateString(),
                    'issued_at' => now(),
                ]);
                $billUlids[] = $bill->ulid;
                $totalExpected += 650000;
            }

            $mockClient = Mockery::mock(BillingApiClient::class);
            $mockClient->shouldReceive('createBilling')
                ->once()
                ->withArgs(fn ($mainForm) => $mainForm['jumlah_tagihan'] === $totalExpected)
                ->andReturn(['uuid' => "uuid-{$monthCount}-months"]);
            $this->app->instance(BillingApiClient::class, $mockClient);

            $response = $this->actingAs($user)->postJson('/api/wali/checkout', [
                'bill_ulids' => $billUlids,
                'method' => 'virtual_account',
            ]);

            $response->assertStatus(201);
            $paymentData = $response->json('payment');

            $this->assertEquals($expectedVa, $paymentData['virtual_account']['va_number'], "VA mismatch for {$monthCount}-month checkout");
            $this->assertEquals($totalExpected, $paymentData['amount']);

            $payment = Payment::where('ulid', $paymentData['ulid'])->first();
            app(PaymentAllocator::class)->settle($payment);

            foreach ($billUlids as $ulid) {
                $bill = Bill::where('ulid', $ulid)->first();
                $this->assertSame('paid', $bill->status, "Bill {$ulid} not paid after settling the {$monthCount}-month payment");
                $this->assertEquals(0.0, (float) $bill->remaining_amount);
            }
        }
    }

    public function test_billing_api_webhook_settles_payment(): void
    {
        $user = User::create(['name' => 'Wali', 'role' => 'orangtua', 'phone' => '081292702075', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Budi', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '600']);

        $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
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
            'due_date' => now()->addDays(7)->toDateString(),
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

    public function test_billing_api_webhook_settles_bsi_va_payment(): void
    {
        $user = User::create(['name' => 'Wali BSI', 'role' => 'orangtua', 'phone' => '081292702099', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali BSI', 'hubungan' => 'ibu']);
        $student = Student::create(['nama_lengkap' => 'Budi BSI', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '602']);

        $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00008',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 700000,
            'total_amount' => 700000,
            'remaining_amount' => 700000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_number' => 'YAPI-SPP-2026-000602',
            'payer_guardian_id' => $guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'external_transaction_id' => 'bill-uuid-602',
            'invoice_id' => 'bill-uuid-602',
            'gateway_response' => [
                'provider' => 'bank_bsi',
                'bank_key' => 'bsi',
                'va_number' => '7895012627000602',
                'billing_uuid' => 'bill-uuid-602',
            ],
        ]);

        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        // The webhook must verify against the BSI VA that was actually
        // registered for this payment - not a Muamalat VA, which is what a
        // prior bug always checked regardless of which bank was chosen.
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('7895012627000602')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->postJson('/api/payment-webhook/trans-uuid-602', [
            'jumlah_pembayaran' => 700000,
            'uuid' => 'trans-uuid-602',
            'billing_uuid' => 'bill-uuid-602',
            'customer_name' => 'Budi BSI',
            'payment_type' => 'PAYMENT',
            'jumlah_tagihan' => 700000,
            'reference_no' => '7895012627000602',
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals('paid', $bill->fresh()->status);
        $this->assertEquals(0, (float) $bill->fresh()->remaining_amount);
    }

    public function test_billing_api_webhook_does_not_settle_when_esp_cannot_be_reached(): void
    {
        $user = User::create(['name' => 'Wali', 'role' => 'orangtua', 'phone' => '081292702076', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Citra', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '601']);

        $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
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
            'due_date' => now()->addDays(7)->toDateString(),
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
            ->andThrow(new BillingApiException('e-SPP unreachable', 500));
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

    /**
     * Regression: per e-SPP's docs (section 5.3), 'bmi' and 'bsm' are BOTH
     * payment-info blocks for the SAME single bill being created - not "one
     * bank's VA in bmi, the other bank's VA in bsm". For a BSI-selected
     * payment, 'bsm.nomor_pembayaran' must be the chosen bank's own VA
     * number (mirroring 'bmi.va_number's role), while 'bsm.id_tagihan'
     * carries the bill's own unique reference (payment_number) - not the VA
     * number, which is stable across every bill for the same (student, fee
     * type, year) and so can't identify one bill among them. An earlier
     * version of this fix set id_tagihan to payment_number but ALSO
     * incorrectly overwrote nomor_pembayaran with payment_number instead of
     * leaving it as the VA - this test locks in the corrected shape.
     */
    public function test_the_bsi_billing_payload_sends_the_va_as_nomor_pembayaran_and_payment_number_as_id_tagihan(): void
    {
        $user = User::create([
            'name' => 'Wali BSI Regression',
            'role' => 'orangtua',
            'phone' => '081292702099',
            'email' => 'walibsiregression@example.com',
            'is_active' => true,
        ]);
        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Wali BSI Regression',
            'hubungan' => 'ayah',
            'no_hp' => '081292702099',
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Regression BSI',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '556',
        ]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/00006',
            'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Bulan Agustus 2026',
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $sppType->id,
            'subtotal' => 650000,
            'total_amount' => 650000,
            'remaining_amount' => 650000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'issued_at' => now(),
        ]);

        $studentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $vaBsi = '7895012627'.$studentCode;

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->once()
            ->withArgs(function (array $mainForm, array $bmi, array $bsm) use ($vaBsi) {
                // nomor_pembayaran is the chosen bank's own VA (same role as
                // bmi.va_number); id_tagihan is the bill's own unique
                // reference (payment_number, starting with the YAPI- prefix
                // CheckoutService assigns) - the two must never be equal.
                return $bsm['nomor_pembayaran'] === $vaBsi
                    && $bsm['id_tagihan'] !== $vaBsi
                    && str_starts_with((string) $bsm['id_tagihan'], 'YAPI-')
                    && $bmi['va_number'] === $vaBsi
                    && $mainForm['bank_id'] === (string) config('services.billing_api.banks.bsi.bank_id', '1');
            })
            ->andReturn(['uuid' => 'bill-uuid-bsi-regression', 'status' => 'success']);

        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);
        $response = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
            'bank' => 'bsi',
        ]);

        $response->assertStatus(201);
    }

    /**
     * A superseded VA payment used to only get marked 'failed' locally -
     * e-SPP's own bill for that VA kept its original date_end and stayed
     * fully payable at the bank counter. PollBillingVaPayments only ever
     * polls pending/processing payments, so a guardian who paid the
     * abandoned VA anyway after switching banks would have had that money
     * land at e-SPP against a bill our side had already stopped watching.
     */
    public function test_switching_bank_shrinks_the_abandoned_vas_validity_window_at_e_spp(): void
    {
        $user = User::create(['name' => 'Wali Ganti Bank', 'role' => 'orangtua', 'phone' => '081292709000', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Ganti Bank', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Anak Ganti Bank', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '703']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/09/00030', 'dedup_key' => 'spp:2026:09:'.$student->id,
            'description' => 'SPP September 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')
            ->twice()
            ->andReturn(['uuid' => 'muamalat-uuid'], ['uuid' => 'bsi-uuid']);
        // The bank-switch path asks about the VA it supersedes before
        // failing it (audit T39-c) - here still outstanding.
        $mockClient->shouldReceive('getByVaNumber')
            ->andReturn(['sisa' => 650000]);
        $mockClient->shouldReceive('updateBilling')
            ->once()
            ->with('muamalat-uuid', Mockery::on(fn ($mainForm) => $mainForm['date_end'] === now()->toDateString()))
            ->andReturn([]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);

        $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
            'bank' => 'muamalat',
        ])->assertStatus(201);

        // Same bill, different bank - this is the "ganti bank" path.
        $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$bill->ulid],
            'method' => 'virtual_account',
            'bank' => 'bsi',
        ])->assertStatus(201);

        // Mockery's mock expectations above (->once(), the exact uuid, the
        // exact date_end) are verified automatically on tearDown - reaching
        // here without a Mockery exception is the assertion.
        $this->assertTrue(true);
    }

    /**
     * expireVa()'s remote call is confirmed non-functional against the real
     * e-SPP API (its PUT endpoint 500s on every payload shape, verified live
     * in PMB, the sibling app against the same e-SPP account, 2026-09-04) -
     * this is the actual safety net: nothing else in this app ever looks at
     * a failed/cancelled Payment again, so a late payment on the still-open
     * superseded VA needs to be caught here or it is lost without a trace.
     */
    public function test_it_logs_a_critical_alert_when_a_superseded_va_is_paid_anyway(): void
    {
        $payment = Payment::create([
            'payment_number' => 'SPP-SUPERSEDED-001',
            'amount' => 650000,
            'method' => 'virtual_account',
            'status' => 'failed',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '8020042728000077'],
            'expires_at' => now()->addDays(2),
            'failed_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->once()
            ->with('8020042728000077')
            ->andReturn(['sisa' => 0, 'jumlah_tagihan' => 650000]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        Log::spy();

        app(BillingApiGateway::class)->checkForSurpriseLatePayment($payment->fresh());

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message, $context) => str_contains($message, 'already superseded')
                && $context['payment'] === 'SPP-SUPERSEDED-001')
            ->once();

        // Not auto-applied - status stays exactly as it was, a human decides.
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_it_says_nothing_when_a_superseded_va_is_still_unpaid(): void
    {
        $payment = Payment::create([
            'payment_number' => 'SPP-SUPERSEDED-002',
            'amount' => 650000,
            'method' => 'virtual_account',
            'status' => 'failed',
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => '8020042728000078'],
            'expires_at' => now()->addDays(2),
            'failed_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->once()
            ->with('8020042728000078')
            ->andReturn(['sisa' => 650000, 'jumlah_tagihan' => 650000]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        Log::spy();

        app(BillingApiGateway::class)->checkForSurpriseLatePayment($payment->fresh());

        Log::shouldNotHaveReceived('critical');
    }

    /** A payment sitting at "processing" with one open bill, ready for webhook tests. */
    private function processingVaPayment(string $nis, array $esppStatus): array
    {
        $user = User::create(['name' => 'Wali Hook '.$nis, 'role' => 'orangtua', 'phone' => '0812927'.$nis, 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Hook', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Anak Hook '.$nis, 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => $nis]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = Bill::create([
            'bill_number' => 'SPP/2026/08/H'.$nis,
            'dedup_key' => 'spp:2026:08:h'.$nis.$student->id,
            'description' => 'SPP Bulan Agustus 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 700000, 'total_amount' => 700000, 'remaining_amount' => 700000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $studentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $va = '8020012627'.$studentCode;

        $payment = Payment::create([
            'payment_number' => 'YAPI-SPP-2026-H'.$nis,
            'payer_guardian_id' => $guardian->id,
            'amount' => 700000,
            'method' => 'virtual_account',
            'status' => 'processing',
            'external_transaction_id' => 'hook-'.$nis,
            'invoice_id' => 'hook-'.$nis,
            'gateway_response' => ['provider' => 'bank_muamalat', 'va_number' => $va, 'billing_uuid' => 'hook-'.$nis],
        ]);
        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')->with($va)->andReturn($esppStatus);
        $this->app->instance(BillingApiClient::class, $mockClient);

        return [$payment, $bill];
    }

    private function postWebhook(string $id): TestResponse
    {
        $nis = substr($id, 6); // "event-903" -> "903"

        return $this->postJson("/api/payment-webhook/{$id}", [
            'uuid' => $id,
            'billing_uuid' => 'hook-'.$nis,
            'reference_no' => null,
            'jumlah_pembayaran' => 700000,
            'payment_type' => 'PAYMENT',
        ]);
    }

    public function test_a_webhook_whose_espp_response_has_no_sisa_field_never_settles(): void
    {
        [$payment, $bill] = $this->processingVaPayment('900', ['status' => 'ok']); // no sisa key

        $this->postWebhook('event-900')->assertOk();

        $this->assertEquals('processing', $payment->fresh()->status);
        $this->assertSame('unpaid', $bill->fresh()->status);
        // Visible on the monitoring screen, not just in laravel.log.
        $this->assertDatabaseHas('integration_events', [
            'source' => 'billing_api',
            'status' => 'failed',
        ]);
    }

    public function test_a_webhook_whose_espp_amount_disagrees_with_the_payment_never_settles(): void
    {
        [$payment, $bill] = $this->processingVaPayment('901', ['sisa' => 0, 'jumlah_tagihan' => 999000]);

        $this->postWebhook('event-901')->assertOk();

        $this->assertEquals('processing', $payment->fresh()->status);
        $this->assertSame('unpaid', $bill->fresh()->status);
        $this->assertDatabaseHas('integration_events', ['source' => 'billing_api', 'status' => 'failed']);
    }

    public function test_money_arriving_for_a_superseded_payment_is_flagged_not_buried(): void
    {
        [$payment, $bill] = $this->processingVaPayment('902', ['sisa' => 0]);
        // The parent was slow, someone re-checked out, this payment lost.
        $payment->forceFill(['status' => 'failed', 'rejection_reason' => 'Digantikan checkout baru'])->save();

        $this->postWebhook('event-902')->assertOk();

        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertSame('unpaid', $bill->fresh()->status);
        $this->assertDatabaseHas('integration_events', [
            'source' => 'billing_api',
            'status' => 'failed',
            'error' => "VA untuk pembayaran {$payment->payment_number} (status: failed) terbayar di e-SPP - uang masuk untuk pembayaran yang sudah ditutup, perlu rekonsiliasi manual.",
        ]);
    }

    public function test_a_redelivered_webhook_settles_exactly_once(): void
    {
        [$payment, $bill] = $this->processingVaPayment('903', ['sisa' => 0]);

        $this->postWebhook('event-903')->assertOk();
        $this->postWebhook('event-903')->assertOk();

        $this->assertEquals('completed', $payment->fresh()->status);
        $this->assertEquals(700000, (float) $bill->fresh()->paid_amount);
        $this->assertSame(1, IntegrationEvent::where('event_id', 'billing_api:event-903')->count());
    }

    public function test_va_prefix_resolution_is_explicit_not_a_fallback_to_spp(): void
    {
        $this->assertSame('802001', BillingApiClient::resolvePrefix('spp'));
        // PMB's ranges on the shared e-SPP account - never minted here.
        $this->assertNull(BillingApiClient::resolvePrefix('uang_pangkal'));
        $this->assertNull(BillingApiClient::resolvePrefix('pendaftaran', null, 'bsi'));
        $this->assertSame('802003', BillingApiClient::resolvePrefix('jamiyyah'));
        $this->assertSame('802005', BillingApiClient::resolvePrefix('ekskul', $this->tkUnit));

        // Fee types e-SPP has no prefix for get NULL, never the SPP prefix -
        // the old fallback minted a VA identical to the student's SPP VA for
        // the same year.
        $this->assertNull(BillingApiClient::resolvePrefix('seragam'));
        $this->assertNull(BillingApiClient::resolvePrefix('buku'));
    }

    public function test_an_explicit_config_prefix_unlocks_an_unmapped_fee_type(): void
    {
        config()->set('services.billing_api.banks.muamalat.va_prefixes.seragam', '802009');

        $this->assertSame('802009', BillingApiClient::resolvePrefix('seragam'));
    }

    public function test_generate_va_number_refuses_an_unmapped_fee_type(): void
    {
        $user = User::create(['name' => 'Wali Seragam', 'role' => 'orangtua', 'phone' => '081292702080', 'is_active' => true]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Wali Seragam', 'hubungan' => 'ayah']);
        $student = Student::create(['nama_lengkap' => 'Anak Seragam', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '801']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $seragam = FeeType::create(['code' => 'seragam', 'name' => 'Seragam & atribut', 'recurrence' => 'once']);

        $this->expectException(BillingApiException::class);
        BillingApiClient::generateVaNumber($student, $seragam);
    }
}
