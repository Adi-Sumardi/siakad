<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproduces the real production shape found in school_units on
 * 2026-09-02: two seedings of the same eight campuses, uppercase codes
 * with inconsistent labels and lowercase codes with the confirmed-correct
 * ones, and two real students/fee_rates/classrooms still pointing at the
 * old (uppercase) batch. Runs the migration's up() directly against that,
 * since RefreshDatabase already ran it once as a no-op before this data
 * existed.
 */
class DedupeSchoolUnitsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_real_data_off_the_old_batch_then_removes_the_duplicates(): void
    {
        DB::table('school_units')->insert([
            ['ulid' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13 Rawamangun', 'jenjang_group' => 'sd', 'is_active' => true, 'sort_order' => 3],
            ['ulid' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp', 'is_active' => true, 'sort_order' => 4],
            ['ulid' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true, 'sort_order' => 4],
            ['ulid' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'smp-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true, 'sort_order' => 5],
        ]);

        $oldSd = DB::table('school_units')->where('code', 'SD-13')->value('id');
        $newSd = DB::table('school_units')->where('code', 'sd-13')->value('id');

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $feeType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $studentId = DB::table('students')->insertGetId([
            'nama_lengkap' => 'Anak Lama', 'jenis_kelamin' => 'L', 'school_unit_id' => $oldSd,
            'entry_year_id' => $year->id, 'nis' => '000700', 'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fee_rates')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'school_unit_id' => $oldSd, 'fee_type_id' => $feeType->id, 'academic_year_id' => $year->id,
            'tingkat' => null, 'amount' => 500000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('classrooms')->insert([
            'school_unit_id' => $oldSd, 'academic_year_id' => $year->id, 'name' => '1-A', 'tingkat' => 1,
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_02_000001_dedupe_school_units.php');
        $migration->up();

        $this->assertDatabaseMissing('school_units', ['code' => 'SD-13']);
        $this->assertDatabaseMissing('school_units', ['code' => 'SMP-12']);
        $this->assertDatabaseHas('school_units', ['code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13']);

        $this->assertSame($newSd, DB::table('students')->where('id', $studentId)->value('school_unit_id'));
        $this->assertSame($newSd, DB::table('fee_rates')->where('academic_year_id', $year->id)->value('school_unit_id'));
        $this->assertSame($newSd, DB::table('classrooms')->where('name', '1-A')->value('school_unit_id'));
    }

    public function test_it_is_a_no_op_when_the_old_batch_was_never_seeded(): void
    {
        DB::table('school_units')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13',
            'jenjang_group' => 'sd', 'is_active' => true, 'sort_order' => 3,
        ]);

        $migration = require database_path('migrations/2026_09_02_000001_dedupe_school_units.php');
        $migration->up();

        $this->assertDatabaseHas('school_units', ['code' => 'sd-13']);
        $this->assertSame(1, DB::table('school_units')->where('code', 'sd-13')->count());
    }
}
