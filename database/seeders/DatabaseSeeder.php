<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
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

        User::updateOrCreate(
            ['email' => 'admin@yapinet.id'],
            [
                'name' => 'Administrator',
                'password' => 'password',
                'role' => 'admin',
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $sd = SchoolUnit::where('code', 'SD-SAKINAH')->first();

        User::updateOrCreate(
            ['email' => 'admin.sd@yapinet.id'],
            [
                'name' => 'Admin SD Sakinah',
                'password' => 'password',
                'role' => 'admin_unit',
                'school_unit_id' => $sd?->id,
                'is_active' => true,
                'activated_at' => now(),
                'email_verified_at' => now(),
            ]
        );
    }
}
