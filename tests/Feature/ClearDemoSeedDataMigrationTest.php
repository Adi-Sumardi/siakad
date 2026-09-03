<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproduces the real production shape found on 2026-09-03: the two seed
 * students, their shared "Adi Sumardi" demo guardian, 4 SPP bills, 3
 * payments, and the two dummy classrooms (1-A, 7-A) they were enrolled in.
 * Runs the migration's up() directly, since RefreshDatabase already ran it
 * once as a no-op before this data existed.
 */
class ClearDemoSeedDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_the_demo_students_their_guardian_bills_payments_and_classrooms(): void
    {
        $unit = SchoolUnit::create(['code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $feeType = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $classroomId = DB::table('classrooms')->insertGetId([
            'school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => '1-A', 'tingkat' => 1,
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A real classroom that must survive untouched.
        DB::table('classrooms')->insert([
            'school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => '2-B', 'tingkat' => 2,
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'nama_lengkap' => 'Muhammad Rayhan Pratama', 'jenis_kelamin' => 'L', 'school_unit_id' => $unit->id,
            'entry_year_id' => $year->id, 'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $studentId, 'classroom_id' => $classroomId, 'academic_year_id' => $year->id,
            'status' => 'active', 'joined_on' => now()->toDateString(),
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $guardianId = DB::table('guardians')->insertGetId([
            'nama' => 'Adi Sumardi', 'hubungan' => 'ayah', 'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('student_guardians')->insert([
            'student_id' => $studentId, 'guardian_id' => $guardianId, 'relationship' => 'ayah',
            'is_primary' => true, 'is_billing_contact' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A real guardian (no children today, e.g. mid-registration) who
        // must survive - proves the delete is scoped by name, not "anyone
        // with zero students".
        DB::table('guardians')->insert([
            'nama' => 'Wali Lain', 'hubungan' => 'ibu', 'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $billId = DB::table('bills')->insertGetId([
            'bill_number' => 'SPP/2026/08/00001', 'dedup_key' => 'spp:2026:08:'.$studentId,
            'description' => 'SPP Agustus', 'student_id' => $studentId, 'academic_year_id' => $year->id,
            'fee_type_id' => $feeType->id, 'subtotal' => 650000, 'total_amount' => 650000, 'remaining_amount' => 0,
            'status' => 'paid', 'due_date' => now()->toDateString(), 'issued_at' => now(),
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $paymentId = DB::table('payments')->insertGetId([
            'payment_number' => 'PAY/20260820/7KOVXV', 'payer_guardian_id' => $guardianId, 'amount' => 650000,
            'status' => 'completed', 'ulid' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_allocations')->insert([
            'bill_id' => $billId, 'payment_id' => $paymentId, 'amount' => 650000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_03_000001_clear_demo_spp_billing_history.php');
        $migration->up();

        $this->assertDatabaseMissing('students', ['id' => $studentId]);
        $this->assertDatabaseMissing('bills', ['id' => $billId]);
        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
        $this->assertDatabaseMissing('payment_allocations', ['bill_id' => $billId]);
        $this->assertDatabaseMissing('enrollments', ['student_id' => $studentId]);
        $this->assertDatabaseMissing('guardians', ['id' => $guardianId]);
        $this->assertDatabaseMissing('classrooms', ['id' => $classroomId]);

        // Untouched: the real classroom and the real guardian.
        $this->assertDatabaseHas('classrooms', ['name' => '2-B']);
        $this->assertDatabaseHas('guardians', ['nama' => 'Wali Lain']);
        $this->assertDatabaseHas('fee_types', ['code' => 'spp']);

        // The fee type is now free of any bill referencing it - deletable.
        $this->assertSame(0, DB::table('bills')->where('fee_type_id', $feeType->id)->count());
    }

    public function test_it_is_a_no_op_when_the_demo_rows_were_never_seeded(): void
    {
        FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        $migration = require database_path('migrations/2026_09_03_000001_clear_demo_spp_billing_history.php');
        $migration->up();

        $this->assertDatabaseHas('fee_types', ['code' => 'spp']);
        $this->assertSame(0, DB::table('students')->count());
    }
}
