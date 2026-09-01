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
        $this->assertEquals('802001' . '2627' . $studentCode, $sppVa);
        $this->assertEquals(16, strlen($sppVa));

        // Jamiyyah Muamalat: 802003 + 2627 + student id
        $jamiyyahVa = BillingApiClient::generateVaNumber($student, $jamiyyahBill, 'muamalat');
        $this->assertEquals('802003' . '2627' . $studentCode, $jamiyyahVa);
        $this->assertEquals(16, strlen($jamiyyahVa));

        // SPP BSI: 365601 + 2627 + student id
        $sppVaBsi = BillingApiClient::generateVaNumber($student, $sppBill, 'bsi');
        $this->assertEquals('365601' . '2627' . $studentCode, $sppVaBsi);
        $this->assertEquals(16, strlen($sppVaBsi));

        // Jamiyyah BSI: 365603 + 2627 + student id
        $jamiyyahVaBsi = BillingApiClient::generateVaNumber($student, $jamiyyahBill, 'bsi');
        $this->assertEquals('365603' . '2627' . $studentCode, $jamiyyahVaBsi);
        $this->assertEquals(16, strlen($jamiyyahVaBsi));
    }

    public function test_it_generates_unit_specific_va_prefixes_for_ekskul(): void
    {
        $ekskulType = FeeType::create(['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term']);

        // 1. TK Unit -> 802005 (Muamalat) & 365605 (BSI)
        $studentTk = Student::create([
            'nama_lengkap' => 'Ananda TK',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->tkUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '100',
        ]);
        $vaTk = BillingApiClient::generateVaNumber($studentTk, $ekskulType, 'muamalat');
        $this->assertEquals('802005' . '2627' . str_pad((string) $studentTk->id, 6, '0', STR_PAD_LEFT), $vaTk);
        $vaTkBsi = BillingApiClient::generateVaNumber($studentTk, $ekskulType, 'bsi');
        $this->assertEquals('365605' . '2627' . str_pad((string) $studentTk->id, 6, '0', STR_PAD_LEFT), $vaTkBsi);

        // 2. SD Unit -> 802006 (Muamalat) & 365606 (BSI)
        $studentSd = Student::create([
            'nama_lengkap' => 'Ananda SD',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '200',
        ]);
        $vaSd = BillingApiClient::generateVaNumber($studentSd, $ekskulType, 'muamalat');
        $this->assertEquals('802006' . '2627' . str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSd);
        $vaSdBsi = BillingApiClient::generateVaNumber($studentSd, $ekskulType, 'bsi');
        $this->assertEquals('365606' . '2627' . str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSdBsi);

        // 3. SMP-12 Unit -> 802007 (Muamalat) & 365607 (BSI)
        $studentSmp12 = Student::create([
            'nama_lengkap' => 'Ananda SMP 12',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp12Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '300',
        ]);
        $vaSmp12 = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType, 'muamalat');
        $this->assertEquals('802007' . '2627' . str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12);
        $vaSmp12Bsi = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType, 'bsi');
        $this->assertEquals('365607' . '2627' . str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12Bsi);

        // 4. SMP-55 Unit -> 802008 (Muamalat) & 365608 (BSI)
        $studentSmp55 = Student::create([
            'nama_lengkap' => 'Ananda SMP 55',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp55Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '400',
        ]);
        $vaSmp55 = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType, 'muamalat');
        $this->assertEquals('802008' . '2627' . str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55);
        $vaSmp55Bsi = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType, 'bsi');
        $this->assertEquals('365608' . '2627' . str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55Bsi);
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
        $this->assertEquals('8020012627' . $expectedStudentCode, $paymentData['virtual_account']['va_number']);
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
        $this->assertEquals('3656012627' . $expectedStudentCode, $paymentData['virtual_account']['va_number']);
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
                'va_number' => '3656012627000602',
                'billing_uuid' => 'bill-uuid-602',
            ],
        ]);

        app(PaymentAllocator::class)->allocate($payment, [$bill->id => 700000]);

        // The webhook must verify against the BSI VA that was actually
        // registered for this payment - not a Muamalat VA, which is what a
        // prior bug always checked regardless of which bank was chosen.
        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('getByVaNumber')
            ->with('3656012627000602')
            ->andReturn(['sisa' => 0]);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $response = $this->postJson('/api/payment-webhook/trans-uuid-602', [
            'jumlah_pembayaran' => 700000,
            'uuid' => 'trans-uuid-602',
            'billing_uuid' => 'bill-uuid-602',
            'customer_name' => 'Budi BSI',
            'payment_type' => 'PAYMENT',
            'jumlah_tagihan' => 700000,
            'reference_no' => '3656012627000602',
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
        $vaBsi = '3656012627'.$studentCode;

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
}
