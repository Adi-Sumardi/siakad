<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Notification\PhoneNumberFormatter;
use App\Services\Security\FieldEncrypter;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * The manual-testing family's NON-SPP bills: jamiyyah, ekskul, buku, seragam
 * - so the wali portal, the admin bill list, receipts, partial payments, and
 * the "cash-only fee types" path can all be exercised by hand. ("Kegiatan"
 * was removed from the catalogue 2026-09-17: the school has no such fee.)
 *
 * Family (already used for manual testing, found-or-created here so a fresh
 * database gets the same cast): wali "Iwan Hadi" with two SMP-12 children,
 * Hafidz (NIS 27002) and Jefren Alpheratz (NIS 27001).
 *
 * Idempotent throughout: bills are FIRST-or-create on a stable dedup key, so
 * re-running never resets a bill that has since been paid or partially paid.
 */
class OtherFeeBillsSeeder extends Seeder
{
    public function run(): void
    {
        $unit = SchoolUnit::where('code', 'SMP-12')->first()
            ?? SchoolUnit::where('jenjang_group', 'smp')->first();

        $year = AcademicYear::where('is_active', true)->firstOrFail();
        $term = Term::where('academic_year_id', $year->id)->firstOrFail();

        // The catalogue itself lives in DatabaseSeeder; these are fallbacks
        // so the seeder also works on a database seeded without it.
        $jamiyyah = $this->type('jamiyyah', 'Uang Jamiyyah', 'monthly');
        $ekskul = $this->type('ekskul', 'Ekstrakurikuler', 'per_term');
        $buku = $this->type('buku', 'Buku', 'per_term');
        $seragam = $this->type('seragam', 'Seragam & atribut', 'once');

        // Prices for the non-SPP types at this unit, so the mass generator
        // has rates to work with too. tingkat null = every level.
        $this->rate($jamiyyah, $unit, $year, 25000);
        $this->rate($ekskul, $unit, $year, 300000);
        $this->rate($buku, $unit, $year, 200000);
        $this->rate($seragam, $unit, $year, 450000);

        // ---- The family --------------------------------------------------
        $hafidz = $this->child($unit, $year, '27002', 'Hafidz');
        $jefren = $this->child($unit, $year, '27001', 'Jefren Alpheratz');
        $guardian = $this->guardianOf($hafidz, $jefren);

        $actor = User::where('role', 'admin')->first()
            ?? User::create([
                'name' => 'Admin Pusat', 'email' => 'admin@yapinet.id', 'role' => 'admin',
                'is_active' => true, 'activated_at' => now(),
            ]);

        // ---- The bills ---------------------------------------------------
        $month = (int) now()->format('m');
        $monthName = now()->translatedFormat('F Y');
        $termName = ucfirst($term->name);

        foreach ([$hafidz, $jefren] as $student) {
            // Jamiyyah, this month, still unpaid.
            $this->bill($student, $jamiyyah, $year, $term, 25000, now()->addDays(7),
                "Uang Jamiyyah {$monthName}", $month);

            // Buku, this term, fully PAID through the real cash lane so the
            // family's receipt and payment history exist too.
            $bukuBill = $this->bill($student, $buku, $year, $term, 200000, now()->subDays(5),
                "Buku Semester {$termName}");
            if ((float) $bukuBill->paid_amount === 0.0) {
                app(CheckoutService::class)->recordManual($bukuBill, 200000, 'cash', $actor, $guardian, 'Seeder: pembayaran buku');
            }

            // Ekskul, this term, PARTLY paid - the "Kurang Bayar / cicilan"
            // state the admin list and the wali portal both render.
            $ekskulBill = $this->bill($student, $ekskul, $year, $term, 300000, now()->addDays(30),
                "Ekstrakurikuler Semester {$termName}");
            if ((float) $ekskulBill->paid_amount === 0.0) {
                app(CheckoutService::class)->recordManual($ekskulBill, 150000, 'cash', $actor, $guardian, 'Seeder: cicilan ekskul');
            }

            // Seragam, unpaid. This fee type has NO registered VA prefix (the
            // school-confirmed list covers 8 prefixes; seragam and buku are
            // not among them), so it is exactly the cash-at-the-desk case the
            // checkout refusal message describes.
            $this->bill($student, $seragam, $year, $term, 450000, now()->addDays(21),
                'Seragam & Atribut');
        }
    }

    private function type(string $code, string $name, string $recurrence): FeeType
    {
        return FeeType::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'recurrence' => $recurrence, 'is_active' => true]
        );
    }

    private function rate(FeeType $type, SchoolUnit $unit, AcademicYear $year, float $amount): FeeRate
    {
        return FeeRate::updateOrCreate(
            ['fee_type_id' => $type->id, 'school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'tingkat' => null],
            ['amount' => $amount, 'due_day' => 10, 'late_fee_amount' => 0, 'is_active' => true]
        );
    }

    /**
     * One bill for one student: first-or-create on a stable dedup key, with a
     * matching PDF line. Existing rows are returned untouched - a re-seed
     * must never reset a bill the family has since paid into.
     */
    private function bill(Student $student, FeeType $type, AcademicYear $year, Term $term, float $amount, CarbonInterface $dueDate, string $description, ?int $month = null): Bill
    {
        $bill = Bill::firstOrCreate(
            [
                'student_id' => $student->id,
                'dedup_key' => "seed:{$type->code}:{$student->id}:{$year->id}".($month ? ":{$month}" : ''),
            ],
            [
                'fee_type_id' => $type->id,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'period_month' => $month,
                'bill_number' => Bill::generateNumber($type, $year->year, $month),
                'description' => $description.' - '.$student->nama_lengkap,
                'subtotal' => $amount,
                'discount_amount' => 0,
                'late_fee' => 0,
                'total_amount' => $amount,
                'paid_amount' => 0,
                'remaining_amount' => $amount,
                'status' => 'unpaid',
                'due_date' => $dueDate->toDateString(),
                'allow_installment' => (bool) $type->allow_installment,
                'issued_at' => now(),
            ]
        );

        if ($bill->wasRecentlyCreated) {
            BillLine::create([
                'bill_id' => $bill->id,
                'name' => $description,
                'qty' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'sort_order' => 0,
            ]);
        }

        return $bill->fresh();
    }

    private function child(SchoolUnit $unit, AcademicYear $year, string $nis, string $name): Student
    {
        $student = Student::updateOrCreate(
            ['nis' => $nis],
            [
                'school_unit_id' => $unit->id,
                'entry_year_id' => $year->id,
                'nama_lengkap' => $name,
                'nama_panggilan' => explode(' ', $name)[0],
                'jenis_kelamin' => 'L',
                'status' => 'active',
            ]
        );

        $class = Classroom::firstOrCreate(
            ['school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => '7-A'],
            ['tingkat' => 7, 'is_active' => true]
        );

        Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $year->id],
            ['classroom_id' => $class->id, 'status' => 'active', 'joined_on' => $year->starts_on ?? now()]
        );

        return $student;
    }

    private function guardianOf(Student ...$students): Guardian
    {
        $phone = '085770583210';
        $email = 'iwanhadi3900@gmail.com';

        $hash = app(FieldEncrypter::class)->blindIndex(PhoneNumberFormatter::toWhatsAppFormat($phone));

        $user = User::where('phone_hash', $hash)->first()
            ?? User::where('email', $email)->first()
            ?? new User;

        $user->fill([
            'name' => 'Iwan Hadi',
            'email' => $email,
            'phone' => $phone,
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        $guardian = Guardian::updateOrCreate(
            ['user_id' => $user->id],
            ['nama' => 'Iwan Hadi', 'hubungan' => 'ayah', 'no_hp' => $phone, 'email' => $email]
        );

        foreach ($students as $student) {
            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true],
            ]);
        }

        return $guardian;
    }
}
