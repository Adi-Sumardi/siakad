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

        // SPP: 802001 + 2627 + student id, padded (16 digits). Keyed on the
        // student's own database id, not NIS - see formatStudentCode().
        $sppVa = BillingApiClient::generateVaNumber($student, $sppBill);
        $this->assertEquals('802001' . '2627' . $studentCode, $sppVa);
        $this->assertEquals(16, strlen($sppVa));

        // Jamiyyah: 802003 + 2627 + student id, padded (16 digits)
        $jamiyyahVa = BillingApiClient::generateVaNumber($student, $jamiyyahBill);
        $this->assertEquals('802003' . '2627' . $studentCode, $jamiyyahVa);
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
        $this->assertEquals('802005' . '2627' . str_pad((string) $studentTk->id, 6, '0', STR_PAD_LEFT), $vaTk);

        // 2. SD Unit -> 802006
        $studentSd = Student::create([
            'nama_lengkap' => 'Ananda SD',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sdUnit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '200',
        ]);
        $vaSd = BillingApiClient::generateVaNumber($studentSd, $ekskulType);
        $this->assertEquals('802006' . '2627' . str_pad((string) $studentSd->id, 6, '0', STR_PAD_LEFT), $vaSd);

        // 3. SMP-12 Unit -> 802007
        $studentSmp12 = Student::create([
            'nama_lengkap' => 'Ananda SMP 12',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->smp12Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '300',
        ]);
        $vaSmp12 = BillingApiClient::generateVaNumber($studentSmp12, $ekskulType);
        $this->assertEquals('802007' . '2627' . str_pad((string) $studentSmp12->id, 6, '0', STR_PAD_LEFT), $vaSmp12);

        // 4. SMP-55 Unit -> 802008
        $studentSmp55 = Student::create([
            'nama_lengkap' => 'Ananda SMP 55',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp55Unit->id,
            'entry_year_id' => $this->year->id,
            'nis' => '400',
        ]);
        $vaSmp55 = BillingApiClient::generateVaNumber($studentSmp55, $ekskulType);
        $this->assertEquals('802008' . '2627' . str_pad((string) $studentSmp55->id, 6, '0', STR_PAD_LEFT), $vaSmp55);
    }

    /**
     * formatStudentCode() used to parse NIS (its trailing 6 digits) or a PMB
     * registration number - both human-entered, both able to collide between
     * two genuinely different students despite each being unique on its own.
     * It's the student's own database id now: two students whose NIS shares
     * the same trailing six digits must not get the same VA student-code.
     */
    public function test_it_formats_student_code_from_the_students_own_id_not_nis(): void
    {
        $s1 = Student::create(['nama_lengkap' => 'S1', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '1000027001']);
        $s2 = Student::create(['nama_lengkap' => 'S2', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => '2000027001']);

        $this->assertEquals(str_pad((string) $s1->id, 6, '0', STR_PAD_LEFT), BillingApiClient::formatStudentCode($s1));
        $this->assertEquals(str_pad((string) $s2->id, 6, '0', STR_PAD_LEFT), BillingApiClient::formatStudentCode($s2));
        $this->assertNotEquals(BillingApiClient::formatStudentCode($s1), BillingApiClient::formatStudentCode($s2));

        // A student with no NIS at all - fresh from PMB, not yet assigned one
        // by the school - gets a code from the same source, not a special case.
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

        $expectedStudentCode = str_pad((string) $student->id, 6, '0', STR_PAD_LEFT);
        $this->assertEquals('8020012627' . $expectedStudentCode, $paymentData['virtual_account']['va_number']);
        $this->assertEquals('Bank Muamalat', $paymentData['virtual_account']['bank_name']);
        $this->assertEquals(650000, $paymentData['amount']);
        $this->assertEquals('processing', $paymentData['status']);
    }

    /**
     * generateVaNumber() is keyed on one student - stable across every bill
     * that student has for a given fee type and year, the way this school's
     * real VA works (a family transfers into the same number every month).
     * A basket spanning two children would register the combined amount
     * under only the first child's VA while quietly covering the other
     * child's bill too - the wali basket UI otherwise actively encourages
     * exactly this ("Diproses dalam 1x transaksi... hemat biaya admin"), so
     * checkout itself has to refuse it for this gateway specifically.
     */
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
            'status' => 'unpaid', 'due_date' => '2026-08-31', 'issued_at' => now(),
        ]);
        $billB = Bill::create([
            'bill_number' => 'SPP/2026/08/00011', 'dedup_key' => 'spp:2026:08:'.$studentB->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $studentB->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 750000, 'total_amount' => 750000, 'remaining_amount' => 750000,
            'status' => 'unpaid', 'due_date' => '2026-08-31', 'issued_at' => now(),
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

    /**
     * A family checks out July's SPP alone, doesn't pay it yet, and later
     * checks out August plus September together - two separate Payment
     * rows, both landing on the identical VA number (generateVaNumber()
     * depends only on student, fee type, and academic year, never the
     * specific bill or checkout). Without superseding the first, both stay
     * live and this gateway has no per-payment invoice to tell a real
     * transfer apart by - only the VA number, which both share.
     */
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
            'status' => 'unpaid', 'due_date' => '2026-07-31', 'issued_at' => now(),
        ]);
        $augBill = Bill::create([
            'bill_number' => 'SPP/2026/08/00021', 'dedup_key' => 'spp:2026:08:'.$student->id,
            'description' => 'SPP Agustus 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => '2026-08-31', 'issued_at' => now(),
        ]);
        $sepBill = Bill::create([
            'bill_number' => 'SPP/2026/09/00022', 'dedup_key' => 'spp:2026:09:'.$student->id,
            'description' => 'SPP September 2026', 'student_id' => $student->id,
            'academic_year_id' => $this->year->id, 'fee_type_id' => $sppType->id,
            'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 650000,
            'status' => 'unpaid', 'due_date' => '2026-09-30', 'issued_at' => now(),
        ]);

        $mockClient = Mockery::mock(BillingApiClient::class);
        $mockClient->shouldReceive('createBilling')->twice()->andReturn(['uuid' => 'uuid-july'], ['uuid' => 'uuid-aug-sep']);
        $this->app->instance(BillingApiClient::class, $mockClient);

        $this->actingAs($user);

        $julyResponse = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$julyBill->ulid],
            'method' => 'virtual_account',
        ]);
        $julyResponse->assertStatus(201);
        $julyPaymentUlid = $julyResponse->json('payment.ulid');

        // Left unpaid - the family checks out August and September together
        // later, without ever settling July.
        $combinedResponse = $this->postJson('/api/wali/checkout', [
            'bill_ulids' => [$augBill->ulid, $sepBill->ulid],
            'method' => 'virtual_account',
        ]);
        $combinedResponse->assertStatus(201);

        $julyPayment = Payment::where('ulid', $julyPaymentUlid)->first();
        $this->assertSame('failed', $julyPayment->fresh()->status);

        // July's bill goes back to open - it was never actually paid, and
        // must not be silently forgotten just because its payment died.
        $this->assertTrue($julyBill->fresh()->isOpen());
        $this->assertEquals(650000, (float) $julyBill->fresh()->remaining_amount);

        // Only one live Bank Muamalat payment remains for this student+fee type.
        $liveCount = Payment::where('payer_guardian_id', $guardian->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();
        $this->assertSame(1, $liveCount);
    }

    /**
     * A family paying several months of one child's SPP in a single
     * checkout - the actual designed-for case ("The whole point is that a
     * parent settles three months of SPP... in a single transaction", per
     * this class's own docblock) - is not the same failure mode as two
     * separate checkouts over time (covered above). Confirms it directly
     * for 3, 5, and 10 months: one VA number regardless of which bill
     * happens to be first (the formula never looks at period_month or due
     * date, only student + fee type + year), one combined amount sent to
     * e-SPP, and every month's bill correctly paid once that one payment
     * settles - not just the "first" one.
     */
    public function test_paying_several_months_of_spp_at_once_uses_one_consistent_va(): void
    {
        foreach ([3, 5, 10] as $monthCount) {
            $user = User::create(['name' => "Wali {$monthCount} bulan", 'role' => 'orangtua', 'phone' => "08129270{$monthCount}000", 'is_active' => true]);
            $guardian = Guardian::create(['user_id' => $user->id, 'nama' => "Wali {$monthCount} bulan", 'hubungan' => 'ayah']);
            $student = Student::create(['nama_lengkap' => "Anak {$monthCount} Bulan", 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sdUnit->id, 'entry_year_id' => $this->year->id, 'nis' => (string) (800 + $monthCount)]);
            $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

            $sppType = FeeType::firstOrCreate(['code' => 'spp'], ['name' => 'SPP', 'recurrence' => 'monthly']);
            $expectedVa = BillingApiClient::generateVaNumber($student, $sppType);

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
                    'due_date' => now()->addDays(10),
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

            // Settling the one payment must mark every month's bill paid,
            // not only the first one the gateway happened to look at.
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
