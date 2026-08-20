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
 * The real unit master comes from PMB via `php artisan units:sync` - the codes
 * below are placeholders so a fresh checkout has something to click through,
 * and must not be treated as the source of truth.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'PG-SAKINAH', 'label' => 'Playgroup (PG) Sakinah', 'jenjang_group' => 'pg'],
            ['code' => 'TK-SAKINAH', 'label' => 'TK Sakinah', 'jenjang_group' => 'tk'],
            ['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd'],
            ['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp'],
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
        $monthly = ['PG-SAKINAH' => 450000, 'TK-SAKINAH' => 500000, 'SD-SAKINAH' => 650000, 'SMP-SAKINAH' => 750000];

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

        $sd = SchoolUnit::where('code', 'SD-SAKINAH')->first();

        User::updateOrCreate(
            ['email' => 'admin.sd@yapinet.id'],
            [
                'name' => 'Admin SD Sakinah',
                'role' => 'admin_unit',
                'school_unit_id' => $sd?->id,
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );
    }
}
