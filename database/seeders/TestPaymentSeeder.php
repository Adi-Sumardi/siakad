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
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestPaymentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure all 8 School Units exist
        $unitsData = [
            ['code' => 'RA-SAKINAH', 'label' => 'RA Sakinah', 'jenjang_group' => 'tk', 'sort_order' => 0],
            ['code' => 'PG-SAKINAH', 'label' => 'Playgroup (PG) Sakinah', 'jenjang_group' => 'pg', 'sort_order' => 1],
            ['code' => 'TK-13', 'label' => 'TK Islam Al Azhar 13', 'jenjang_group' => 'tk', 'sort_order' => 2],
            ['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13 Rawamangun', 'jenjang_group' => 'sd', 'sort_order' => 3],
            ['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp', 'sort_order' => 4],
            ['code' => 'SMP-55', 'label' => 'SMP Islam Al Azhar 55 Jatiasih', 'jenjang_group' => 'smp', 'sort_order' => 5],
            ['code' => 'SMA-33', 'label' => 'SMA Islam Al Azhar 33', 'jenjang_group' => 'sma', 'sort_order' => 6],
            ['code' => 'SMA-48', 'label' => 'SMA Islam Al Azhar 48', 'jenjang_group' => 'sma', 'sort_order' => 7],
        ];

        $units = [];
        foreach ($unitsData as $ud) {
            $units[$ud['code']] = SchoolUnit::updateOrCreate(
                ['code' => $ud['code']],
                ['label' => $ud['label'], 'jenjang_group' => $ud['jenjang_group'], 'sort_order' => $ud['sort_order'], 'is_active' => true]
            );
        }

        // Also ensure legacy codes if any exist
        $sdUnit = $units['SD-13'] ?? SchoolUnit::where('jenjang_group', 'sd')->first();
        $smpUnit = $units['SMP-12'] ?? SchoolUnit::where('jenjang_group', 'smp')->first();

        // 2. Ensure Academic Year & Active Term
        $year = AcademicYear::where('is_active', true)->first()
            ?? AcademicYear::firstOrCreate(['year' => '2026/2027'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $term = Term::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'ganjil'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true]
        );

        // 3. Ensure Fee Types (SPP, Kegiatan, etc.)
        $sppType = FeeType::firstOrCreate(
            ['code' => 'spp'],
            ['name' => 'SPP Bulanan', 'recurrence' => 'monthly', 'allow_installment' => true, 'sort_order' => 0, 'is_active' => true]
        );

        // 4. Ensure Fee Rates for SD and SMP 12
        $sdRate = FeeRate::updateOrCreate(
            [
                'fee_type_id' => $sppType->id,
                'school_unit_id' => $sdUnit->id,
                'academic_year_id' => $year->id,
                'tingkat' => 1,
            ],
            ['amount' => 650000, 'due_day' => 10, 'late_fee_amount' => 0, 'is_active' => true]
        );

        $smpRate = FeeRate::updateOrCreate(
            [
                'fee_type_id' => $sppType->id,
                'school_unit_id' => $smpUnit->id,
                'academic_year_id' => $year->id,
                'tingkat' => 7,
            ],
            ['amount' => 750000, 'due_day' => 10, 'late_fee_amount' => 0, 'is_active' => true]
        );

        // 5. Create / Update User Wali Murid with requested WhatsApp phone 081292702075 & email adiesumardy@gmail.com
        $guardianPhone = '081292702075';
        $guardianEmail = 'adiesumardy@gmail.com';
        $guardianName = 'Adi Sumardi (Wali)';

        // Ensure admin user adisumardi888@gmail.com stays clean as admin
        $adminUser = User::where('email', 'adisumardi888@gmail.com')->first();
        if ($adminUser) {
            $adminUser->role = 'admin';
            $adminUser->phone = null;
            $adminUser->save();
        }

        $encrypter = app(\App\Services\Security\FieldEncrypter::class);
        $phoneHash = $encrypter->blindIndex(\App\Services\Notification\PhoneNumberFormatter::toWhatsAppFormat($guardianPhone));

        $user = User::where('phone_hash', $phoneHash)->first()
            ?? User::where('email', $guardianEmail)->first()
            ?? new User();

        $user->name = $guardianName;
        $user->email = $guardianEmail;
        $user->phone = $guardianPhone;
        $user->role = 'orangtua';
        $user->is_active = true;
        $user->activated_at = now();
        $user->email_verified_at = now();

        try {
            $user->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Neither lookup above found the row this phone number actually
            // belongs to - most likely APP_KEY changed since it was written,
            // which changes the blind index everything is hashed against
            // (see docs: encryption at rest / APP_KEY). Whatever the cause,
            // a seeder crashing over a re-seed is worse than reusing the row
            // its own hash points at.
            $user = User::where('phone_hash', $phoneHash)->firstOrFail();
            $user->name = $guardianName;
            $user->email = $guardianEmail;
            $user->role = 'orangtua';
            $user->is_active = true;
            $user->activated_at = now();
            $user->email_verified_at = now();
            $user->save();
        }

        $guardian = Guardian::updateOrCreate(
            ['user_id' => $user->id],
            [
                'nama' => 'Adi Sumardi',
                'hubungan' => 'ayah',
                'no_hp' => $guardianPhone,
                'email' => $guardianEmail,
                'alamat' => 'Jakarta Timur',
            ]
        );

        // 6. Create Child 1: Jenjang SD (SD Islam Al Azhar 13)
        $classSD = Classroom::firstOrCreate(
            [
                'school_unit_id' => $sdUnit->id,
                'academic_year_id' => $year->id,
                'name' => '1-A',
            ],
            ['tingkat' => 1, 'is_active' => true]
        );

        $studentSD = Student::updateOrCreate(
            ['nis' => '202613001'],
            [
                'school_unit_id' => $sdUnit->id,
                'entry_year_id' => $year->id,
                'nama_lengkap' => 'Muhammad Rayhan Pratama',
                'nama_panggilan' => 'Rayhan',
                'jenis_kelamin' => 'L',
                'status' => 'active',
            ]
        );

        Enrollment::updateOrCreate(
            [
                'student_id' => $studentSD->id,
                'academic_year_id' => $year->id,
            ],
            [
                'classroom_id' => $classSD->id,
                'status' => 'active',
                'joined_on' => $year->starts_on ?? now(),
            ]
        );

        $studentSD->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => 'ayah',
                'is_primary' => true,
                'is_billing_contact' => true,
            ],
        ]);

        // 7. Create Child 2: Jenjang SMP (SMP Islam Al Azhar 12)
        $classSMP = Classroom::firstOrCreate(
            [
                'school_unit_id' => $smpUnit->id,
                'academic_year_id' => $year->id,
                'name' => '7-A',
            ],
            ['tingkat' => 7, 'is_active' => true]
        );

        $studentSMP = Student::updateOrCreate(
            ['nis' => '202612001'],
            [
                'school_unit_id' => $smpUnit->id,
                'entry_year_id' => $year->id,
                'nama_lengkap' => 'Aisyah Putri Azzahra',
                'nama_panggilan' => 'Aisyah',
                'jenis_kelamin' => 'P',
                'status' => 'active',
            ]
        );

        Enrollment::updateOrCreate(
            [
                'student_id' => $studentSMP->id,
                'academic_year_id' => $year->id,
            ],
            [
                'classroom_id' => $classSMP->id,
                'status' => 'active',
                'joined_on' => $year->starts_on ?? now(),
            ]
        );

        $studentSMP->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => 'ayah',
                'is_primary' => true,
                'is_billing_contact' => true,
            ],
        ]);

        // 8. Generate SPP Bills for both students (Current & Upcoming Month for payment testing)
        $currentMonth = (int) now()->format('m');
        $monthName = now()->translatedFormat('F Y');

        // Bill for SD Student (Rp 650.000)
        $billSD = Bill::where('student_id', $studentSD->id)
            ->where('fee_type_id', $sppType->id)
            ->where('academic_year_id', $year->id)
            ->where('period_month', $currentMonth)
            ->first();

        if (! $billSD) {
            $billSD = Bill::create([
                'student_id' => $studentSD->id,
                'fee_type_id' => $sppType->id,
                'academic_year_id' => $year->id,
                'period_month' => $currentMonth,
                'bill_number' => Bill::generateNumber($sppType, $year->year, $currentMonth),
                'term_id' => $term->id,
                'fee_rate_id' => $sdRate->id,
                'dedup_key' => "spp:{$studentSD->id}:{$year->id}:{$currentMonth}",
                'description' => "SPP {$monthName} - {$studentSD->nama_lengkap} (SD Al Azhar 13)",
                'subtotal' => 650000,
                'discount_amount' => 0,
                'late_fee' => 0,
                'total_amount' => 650000,
                'paid_amount' => 0,
                'remaining_amount' => 650000,
                'status' => 'unpaid',
                'due_date' => now()->addDays(10)->toDateString(),
                'allow_installment' => true,
                'issued_at' => now(),
            ]);
        }

        BillLine::updateOrCreate(
            ['bill_id' => $billSD->id, 'name' => "SPP {$monthName}"],
            [
                'qty' => 1,
                'unit_price' => 650000,
                'amount' => 650000,
                'sort_order' => 0,
            ]
        );

        // Bill for SMP 12 Student (Rp 750.000)
        $billSMP = Bill::where('student_id', $studentSMP->id)
            ->where('fee_type_id', $sppType->id)
            ->where('academic_year_id', $year->id)
            ->where('period_month', $currentMonth)
            ->first();

        if (! $billSMP) {
            $billSMP = Bill::create([
                'student_id' => $studentSMP->id,
                'fee_type_id' => $sppType->id,
                'academic_year_id' => $year->id,
                'period_month' => $currentMonth,
                'bill_number' => Bill::generateNumber($sppType, $year->year, $currentMonth),
                'term_id' => $term->id,
                'fee_rate_id' => $smpRate->id,
                'dedup_key' => "spp:{$studentSMP->id}:{$year->id}:{$currentMonth}",
                'description' => "SPP {$monthName} - {$studentSMP->nama_lengkap} (SMP Al Azhar 12)",
                'subtotal' => 750000,
                'discount_amount' => 0,
                'late_fee' => 0,
                'total_amount' => 750000,
                'paid_amount' => 0,
                'remaining_amount' => 750000,
                'status' => 'unpaid',
                'due_date' => now()->addDays(10)->toDateString(),
                'allow_installment' => true,
                'issued_at' => now(),
            ]);
        }

        BillLine::updateOrCreate(
            ['bill_id' => $billSMP->id, 'name' => "SPP {$monthName}"],
            [
                'qty' => 1,
                'unit_price' => 750000,
                'amount' => 750000,
                'sort_order' => 0,
            ]
        );
    }
}
