<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\DiscountScheme;
use App\Models\Enrollment;
use App\Models\FeeComponent;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The Fase 2 spine: bills are issued once and only once, a payment can settle
 * several of them at a time, and no bill's balance is ever kept anywhere but in
 * the allocations it can be recomputed from.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        // Local checkout must not reach the network; the real gateway is proven
        // separately by its own contract, not by every billing test.
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

        $this->spp = FeeType::create([
            'code' => 'spp',
            'name' => 'SPP',
            'recurrence' => 'monthly',
        ]);
    }

    private function rate(float $amount = 650000, ?int $tingkat = null, ?FeeType $type = null): FeeRate
    {
        return FeeRate::create([
            'fee_type_id' => ($type ?? $this->spp)->id,
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'tingkat' => $tingkat,
            'amount' => $amount,
            'due_day' => 10,
        ]);
    }

    private function student(string $name = 'Aisyah Nur Ramadhani', ?int $tingkat = null): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name,
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        if ($tingkat !== null) {
            $classroom = Classroom::firstOrCreate([
                'school_unit_id' => $this->unit->id,
                'academic_year_id' => $this->year->id,
                'name' => $tingkat.'A',
            ], ['tingkat' => $tingkat]);

            Enrollment::create([
                'student_id' => $student->id,
                'classroom_id' => $classroom->id,
                'academic_year_id' => $this->year->id,
                'joined_on' => now(),
            ]);
        }

        return $student->fresh();
    }

    /** A guardian with a login, holding the given children. */
    private function guardianFor(Student ...$students): User
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

        foreach ($students as $student) {
            $student->guardians()->attach($guardian->id, [
                'relationship' => 'ayah',
                'is_primary' => true,
                'is_billing_contact' => true,
            ]);
        }

        return $user;
    }

    private function generator(): BillGenerator
    {
        return app(BillGenerator::class);
    }

    public function test_it_issues_one_bill_per_active_student(): void
    {
        $this->rate();
        $this->student('Aisyah');
        $this->student('Fathan');

        $run = $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $this->assertSame(2, $run->bills_created);
        $this->assertEquals(1300000.0, (float) $run->total_amount);
        $this->assertDatabaseCount('bills', 2);

        $bill = Bill::first();
        $this->assertSame('spp:2026-2027:08', $bill->dedup_key);
        $this->assertSame('unpaid', $bill->status);
        $this->assertEquals(650000.0, (float) $bill->remaining_amount);
        // A plain fee still gets a line, so every bill prints the same way.
        $this->assertCount(1, $bill->lines);
    }

    public function test_a_bill_number_collision_from_an_out_of_sequence_row_is_retried_not_fatal(): void
    {
        $this->rate();
        $student = $this->student();

        // A row that count()+1 does not predict: one bill exists, so the next
        // call computes sequence 2 - but something else (a seeder, another
        // run) already claimed that exact number for an unrelated bill. This
        // is exactly the shape of the collision seen in production logs.
        // Graduated, so the generator itself never tries to bill them - the
        // stray row is only here to occupy a number out of sequence.
        $strayStudent = Student::create([
            'nama_lengkap' => 'Sudah Lulus', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id, 'status' => 'graduated',
        ]);
        Bill::create([
            'student_id' => $strayStudent->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => 'unrelated-dedup-key',
            'bill_number' => 'SPP/2026/08/00002',
            'description' => 'Out-of-sequence bill', 'subtotal' => 1, 'total_amount' => 1,
            'remaining_amount' => 1, 'status' => 'unpaid', 'due_date' => now(), 'issued_at' => now(),
        ]);

        $run = $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $this->assertSame(1, $run->bills_created);
        $bill = Bill::where('student_id', $student->id)->first();
        // Retried past the collision to a number nothing else holds.
        $this->assertNotSame('SPP/2026/08/00002', $bill->bill_number);
    }

    public function test_running_the_generator_twice_issues_nothing_the_second_time(): void
    {
        $this->rate();
        $this->student();

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);
        $second = $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        // The scheduler firing twice, or an admin pressing the button again,
        // must not double-bill a family.
        $this->assertSame(0, $second->bills_created);
        $this->assertSame(1, $second->bills_skipped);
        $this->assertDatabaseCount('bills', 1);
        $this->assertSame('Sudah punya tagihan', $second->skipped_detail[0]['reason']);
    }

    public function test_a_student_with_no_matching_rate_is_named_not_guessed_at(): void
    {
        $this->rate(650000, tingkat: 1);
        $this->student('Punya tarif', tingkat: 1);
        $this->student('Tanpa tarif', tingkat: 3);

        $preview = $this->generator()->preview($this->spp, $this->year, $this->unit, 8);

        $this->assertSame(1, $preview['eligible']);
        $this->assertCount(1, $preview['skipped']);
        $this->assertSame('Tanpa tarif', $preview['skipped'][0]['student']);
        $this->assertSame('Tarif belum ada', $preview['skipped'][0]['reason']);
    }

    public function test_a_fee_type_needing_item_selection_is_skipped_not_guessed_at(): void
    {
        $seragam = FeeType::create([
            'code' => 'seragam', 'name' => 'Seragam & atribut', 'recurrence' => 'once',
            'requires_selection' => true,
        ]);
        FeeRate::create([
            'fee_type_id' => $seragam->id, 'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id, 'amount' => 300000,
        ]);
        $this->student();

        $preview = $this->generator()->preview($seragam, $this->year, $this->unit);

        // A rate exists, but nothing anywhere lets a family choose which
        // items or sizes apply to them - billing the full bundle to everyone
        // would be a guess, not a bill.
        $this->assertSame(0, $preview['eligible']);
        $this->assertCount(1, $preview['skipped']);
        $this->assertSame('Perlu pemilihan item', $preview['skipped'][0]['reason']);
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_preview_writes_nothing(): void
    {
        $this->rate();
        $this->student();

        $preview = $this->generator()->preview($this->spp, $this->year, $this->unit, 8);

        $this->assertSame(1, $preview['eligible']);
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('billing_runs', 0);
    }

    public function test_a_level_specific_rate_beats_the_unit_wide_one(): void
    {
        $this->rate(650000);                    // whole unit
        $this->rate(750000, tingkat: 6);        // kelas 6 only

        $this->student('Kelas 1', tingkat: 1);
        $this->student('Kelas 6', tingkat: 6);

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $this->assertEquals(650000.0, (float) Bill::whereHas('student', fn ($q) => $q->where('nama_lengkap', 'Kelas 1'))->first()->total_amount);
        $this->assertEquals(750000.0, (float) Bill::whereHas('student', fn ($q) => $q->where('nama_lengkap', 'Kelas 6'))->first()->total_amount);
    }

    public function test_only_active_students_are_billed(): void
    {
        $this->rate();
        $this->student('Aktif');

        Student::create([
            'nama_lengkap' => 'Sudah pindah',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id,
            'status' => 'transferred',
        ]);

        $run = $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        // Chasing a family whose child left is the worst kind of billing bug.
        $this->assertSame(1, $run->bills_created);
    }

    public function test_a_discount_is_frozen_onto_the_bill_as_a_line(): void
    {
        $this->rate(650000);
        $student = $this->student();

        $scheme = DiscountScheme::create([
            'code' => 'YATIM',
            'name' => 'Subsidi yatim',
            'type' => 'percent',
            'value' => 20,
        ]);

        StudentDiscount::create([
            'student_id' => $student->id,
            'discount_scheme_id' => $scheme->id,
            'academic_year_id' => $this->year->id,
            'effective_from' => now()->subMonth(),
        ]);

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $this->assertEquals(130000.0, (float) $bill->discount_amount);
        $this->assertEquals(520000.0, (float) $bill->total_amount);

        // The printed lines must sum to the total, or the invoice needs a
        // footnote nobody reads.
        $this->assertEquals(520000.0, (float) $bill->lines->sum('amount'));

        // Editing the scheme afterwards must not move an amount already sent to
        // a family.
        $scheme->update(['value' => 50]);
        $this->assertEquals(520000.0, (float) $bill->fresh()->total_amount);
    }

    public function test_a_discount_that_has_expired_is_not_applied(): void
    {
        $this->rate(650000);
        $student = $this->student();

        $scheme = DiscountScheme::create(['code' => 'X', 'name' => 'Lama', 'type' => 'nominal', 'value' => 100000]);

        StudentDiscount::create([
            'student_id' => $student->id,
            'discount_scheme_id' => $scheme->id,
            'academic_year_id' => $this->year->id,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subMonth(),
        ]);

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $this->assertEquals(0.0, (float) Bill::first()->discount_amount);
    }

    public function test_a_packaged_fee_expands_into_its_components(): void
    {
        $seragam = FeeType::create(['code' => 'seragam', 'name' => 'Seragam', 'recurrence' => 'once']);
        $rate = $this->rate(875000, type: $seragam);

        foreach ([['Kemeja putih', 200000, 2], ['Celana panjang', 250000, 1], ['Seragam olahraga', 225000, 1]] as $i => [$name, $price, $qty]) {
            FeeComponent::create([
                'fee_rate_id' => $rate->id,
                'name' => $name,
                'amount' => $price,
                'default_qty' => $qty,
                'sort_order' => $i,
            ]);
        }

        $this->student();
        $this->generator()->run($seragam, $this->year, $this->unit);

        $bill = Bill::first();
        // A parent asking what the Rp 875.000 covers gets an itemised answer.
        $this->assertCount(3, $bill->lines);
        $this->assertEquals(400000.0, (float) $bill->lines->firstWhere('name', 'Kemeja putih')->amount);
    }

    public function test_a_bill_is_only_settled_when_the_payment_completes(): void
    {
        $this->rate();
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $payment = app(CheckoutService::class)->start($user, [$bill->ulid], 'virtual_account');

        // Checkout started but unpaid: the bill must still read as owing, or
        // nobody chases an abandoned invoice. Which open status it holds
        // depends on the due date, and that is not what this test is about.
        $this->assertTrue($bill->fresh()->isOpen());
        $this->assertEquals(0.0, (float) $bill->fresh()->paid_amount);

        app(PaymentAllocator::class)->settle($payment);

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertEquals(650000.0, (float) $bill->fresh()->paid_amount);
        $this->assertNotNull($bill->fresh()->paid_at);
    }

    public function test_one_payment_settles_several_bills_across_two_children(): void
    {
        $this->rate();
        $aisyah = $this->student('Aisyah');
        $fathan = $this->student('Fathan');
        $user = $this->guardianFor($aisyah, $fathan);

        $this->generator()->run($this->spp, $this->year, $this->unit, 7);
        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bills = Bill::open()->get();
        $this->assertCount(4, $bills);

        $payment = app(CheckoutService::class)->start(
            $user,
            $bills->pluck('ulid')->all(),
            'virtual_account',
        );

        // Four bills, two children, one transaction and one bank admin fee.
        $this->assertEquals(2600000.0, (float) $payment->amount);
        $this->assertCount(4, $payment->allocations);

        app(PaymentAllocator::class)->settle($payment);

        $this->assertSame(0, Bill::open()->count());
    }

    public function test_a_part_payment_leaves_the_bill_partial(): void
    {
        $this->rate();
        $this->student();
        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@yapinet.id',
            'role' => 'admin', 'is_active' => true, 'activated_at' => now(),
        ]);

        app(CheckoutService::class)->recordManual($bill, 200000, 'cash', $admin);

        $bill->refresh();
        $this->assertSame('partial', $bill->status);
        $this->assertEquals(450000.0, (float) $bill->remaining_amount);
        $this->assertNull($bill->paid_at);
    }

    public function test_settling_the_same_payment_twice_does_not_double_count(): void
    {
        $this->rate();
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $payment = app(CheckoutService::class)->start($user, [$bill->ulid], 'virtual_account');

        $allocator = app(PaymentAllocator::class);
        $allocator->settle($payment);
        $allocator->settle($payment->fresh());

        // Xendit retries; the balance must not move on the second delivery.
        $this->assertEquals(650000.0, (float) $bill->fresh()->paid_amount);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_a_second_checkout_on_the_same_bill_supersedes_the_first(): void
    {
        $this->rate();
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $bill = Bill::first();
        $checkout = app(CheckoutService::class);
        $allocator = app(PaymentAllocator::class);

        // A double-click, or two open tabs: the same still-open bill checked
        // out twice before either payment is settled.
        $first = $checkout->start($user, [$bill->ulid], 'virtual_account');
        $second = $checkout->start($user, [$bill->ulid], 'virtual_account');

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('processing', $second->fresh()->status);

        // If the family (or a late gateway callback) somehow settles both -
        // two Xendit invoices, two transfers - the bill must not end up
        // marked paid for more than it was ever owed.
        $allocator->settle($first->fresh());
        $allocator->settle($second->fresh());

        $this->assertSame('failed', $first->fresh()->status);
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertEquals(650000.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame('paid', $bill->fresh()->status);
    }

    public function test_a_guardian_cannot_pay_another_familys_bill(): void
    {
        $this->rate();
        $mine = $this->student('Anak saya');
        $theirs = $this->student('Anak orang lain');

        $user = $this->guardianFor($mine);
        $this->guardianFor($theirs);

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);
        $otherBill = Bill::whereHas('student', fn ($q) => $q->where('nama_lengkap', 'Anak orang lain'))->first();

        $this->actingAs($user)
            ->postJson('/api/wali/checkout', [
                'bill_ulids' => [$otherBill->ulid],
                'method' => 'virtual_account',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_bill_list_covers_every_child_at_once(): void
    {
        $this->rate();
        $aisyah = $this->student('Aisyah');
        $fathan = $this->student('Fathan');
        $this->student('Bukan anak saya');
        $user = $this->guardianFor($aisyah, $fathan);

        $this->generator()->run($this->spp, $this->year, $this->unit, 8);

        $this->actingAs($user)
            ->getJson('/api/wali/bills?status=open')
            ->assertOk()
            ->assertJsonCount(2, 'bills')
            ->assertJsonPath('summary.outstanding', 1300000);
    }

    public function test_overdue_bills_are_marked_by_the_scheduled_command(): void
    {
        $this->rate();
        $this->student();
        $this->generator()->run($this->spp, $this->year, $this->unit, 8, dueDate: now()->subDays(3));

        $this->artisan('bills:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', Bill::first()->status);
    }
}
