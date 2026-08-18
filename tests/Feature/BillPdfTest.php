<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * One template renders both faces of a bill - invoice while owed, receipt once
 * settled - so what matters here is that ownership is checked before anything
 * is rendered, and that the file actually comes back as a PDF.
 */
class BillPdfTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
            {
                $payment->forceFill([
                    'status' => 'processing',
                    'invoice_id' => 'inv_test_'.$payment->id,
                    'invoice_url' => 'https://checkout.test/'.$payment->payment_number,
                ])->save();

                return $payment;
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

    private function billedStudent(string $name = 'Aisyah Nur Ramadhani'): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name,
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        app(BillGenerator::class)->run($this->spp, $this->year, $this->unit, 8);

        return $student->fresh();
    }

    private function guardianFor(Student $student): User
    {
        $user = User::create([
            'name' => 'Budi Ramadhani',
            'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id,
            'nama' => 'Budi Ramadhani',
            'hubungan' => 'ayah',
            'email' => $user->email,
        ]);

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah',
            'is_primary' => true,
            'is_billing_contact' => true,
        ]);

        return $user;
    }

    public function test_a_guardian_can_download_their_own_bill_as_a_pdf(): void
    {
        $student = $this->billedStudent();
        $user = $this->guardianFor($student);
        $bill = Bill::first();

        $response = $this->actingAs($user)->get("/api/wali/bills/{$bill->ulid}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // A real PDF, not an error page mislabelled - dompdf writes the file
        // signature first.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_guardian_cannot_download_another_familys_bill(): void
    {
        $mine = $this->billedStudent('Anak saya');
        $theirs = $this->billedStudent('Anak orang lain');

        $user = $this->guardianFor($mine);
        $this->guardianFor($theirs);

        $otherBill = Bill::whereHas('student', fn ($q) => $q->where('nama_lengkap', 'Anak orang lain'))->first();

        // 404, not 403: confirming the bill exists at all would already leak
        // something about a family that is not theirs.
        $this->actingAs($user)
            ->get("/api/wali/bills/{$otherBill->ulid}/pdf")
            ->assertStatus(404);
    }

    public function test_an_admin_outside_the_unit_cannot_download_the_bill(): void
    {
        $student = $this->billedStudent();
        $bill = Bill::first();

        $otherUnit = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $admin = User::create([
            'name' => 'Admin SMP',
            'email' => 'admin.smp@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $otherUnit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("/api/admin/bills/{$bill->ulid}/pdf")
            ->assertStatus(404);
    }

    public function test_a_paid_bill_renders_as_a_receipt_with_a_stamp(): void
    {
        $student = $this->billedStudent();
        $bill = Bill::first();

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@yapinet.id',
            'role' => 'admin', 'is_active' => true, 'activated_at' => now(),
        ]);

        app(CheckoutService::class)->recordManual($bill, 650000, 'cash', $admin);

        $response = $this->actingAs($admin)->get("/api/admin/bills/{$bill->fresh()->ulid}/pdf");

        $response->assertOk();
        // "attachment" would force a download dialog; "stream" lets the browser
        // preview it inline, which is what a receipt link should do.
        $this->assertStringContainsString('Kuitansi', $response->headers->get('Content-Disposition'));
    }

    public function test_an_unpaid_bill_renders_as_an_invoice(): void
    {
        $student = $this->billedStudent();
        $bill = Bill::first();

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin2@yapinet.id',
            'role' => 'admin', 'is_active' => true, 'activated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get("/api/admin/bills/{$bill->ulid}/pdf");

        $this->assertStringContainsString('Tagihan', $response->headers->get('Content-Disposition'));
    }
}
