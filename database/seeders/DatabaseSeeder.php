<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development seed only.
 *
 * The unit list below is the dev copy of the real campus catalogue (confirmed
 * by the school, 2026-09-09) and the single place it lives - TestPaymentSeeder
 * looks these up by code instead of carrying a second list that drifts. In
 * production the master still comes from PMB via `php artisan units:sync`,
 * matched on `code`, so codes here must not be renamed casually.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'PG-SAKINAH', 'label' => 'Playgroup Sakinah Rawamangun', 'jenjang_group' => 'pg'],
            ['code' => 'RA-SAKINAH', 'label' => 'RA Sakinah Kebayoran Baru', 'jenjang_group' => 'tk'],
            ['code' => 'TK-13', 'label' => 'TKI Al Azhar 13 Rawamangun', 'jenjang_group' => 'tk'],
            ['code' => 'SD-13', 'label' => 'SDI Al Azhar 13 Rawamangun', 'jenjang_group' => 'sd'],
            ['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp'],
            ['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp'],
            ['code' => 'SMA-33', 'label' => 'SMAI Al Azhar 33 Jatimakmur', 'jenjang_group' => 'sma'],
        ];

        foreach ($units as $i => $unit) {
            SchoolUnit::updateOrCreate(
                ['code' => $unit['code']],
                $unit + ['sort_order' => $i, 'is_active' => true]
            );
        }

        $year = AcademicYear::updateOrCreate(
            ['year' => '2026/2027'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']
        );
        $year->activate();

        Term::updateOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'ganjil'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true]
        );
        Term::updateOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'genap'],
            ['starts_on' => '2027-01-01', 'ends_on' => '2027-06-30', 'is_active' => false]
        );

        // Staff sign in with a one-time code like everyone else. With the
        // gateways unset locally, `php artisan otp:issue admin@yapinet.id`
        // prints one to the terminal.
        User::updateOrCreate(
            ['email' => 'admin@yapinet.id'],
            [
                'name' => 'Administrator',
                'role' => 'admin',
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'adisumardi888@gmail.com'],
            [
                'name' => 'Administrator',
                'role' => 'admin',
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        // Fee catalogue. Codes are the contract the generator and every
        // dedup_key are built on, so they are seeded rather than typed in.
        $feeTypes = [
            ['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly'],
            ['code' => 'jamiyyah', 'name' => 'Uang Jamiyyah', 'recurrence' => 'monthly'],
            ['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term'],
            ['code' => 'seragam', 'name' => 'Seragam & atribut', 'recurrence' => 'once', 'allow_installment' => true, 'requires_selection' => true],
            ['code' => 'buku', 'name' => 'Buku', 'recurrence' => 'per_term'],
            ['code' => 'kegiatan', 'name' => 'Kegiatan', 'recurrence' => 'per_term'],
        ];

        foreach ($feeTypes as $i => $type) {
            FeeType::updateOrCreate(['code' => $type['code']], $type + ['sort_order' => $i]);
        }

        // Dev rates only. Real amounts are set per unit in Pengaturan; these
        // exist so a fresh checkout can run the generator and see something.
        $spp = FeeType::where('code', 'spp')->first();
        $monthly = ['PG-SAKINAH' => 450000, 'TK-13' => 500000, 'SD-13' => 650000, 'SMP-12' => 750000];

        foreach ($monthly as $code => $amount) {
            $unit = SchoolUnit::where('code', $code)->first();

            FeeRate::updateOrCreate(
                [
                    'fee_type_id' => $spp->id,
                    'school_unit_id' => $unit->id,
                    'academic_year_id' => $year->id,
                    'tingkat' => null,
                ],
                ['amount' => $amount, 'due_day' => 10, 'late_fee_amount' => 25000, 'late_fee_grace_days' => 7]
            );
        }

        // Sits on the unit the seeded students belong to, so admin_unit flows
        // can be exercised against real rows straight after a fresh seed.
        $sd = SchoolUnit::where('code', 'SD-13')->first();

        User::updateOrCreate(
            ['email' => 'admin.sd@yapinet.id'],
            [
                'name' => 'Admin SDI Al Azhar 13',
                'role' => 'admin_unit',
                'school_unit_id' => $sd?->id,
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $this->call(TestPaymentSeeder::class);
    }
}
