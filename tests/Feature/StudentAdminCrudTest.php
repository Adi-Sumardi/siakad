<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing and removing a student is central-admin only - a unit's own
 * TU/admin_unit can view and export their students (StudentController::index)
 * but must not be able to change or delete them.
 */
class StudentAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $unitAdmin;
    private SchoolUnit $unit;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Administrator', 'email' => 'admin@example.com', 'phone' => '081111111111',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->unitAdmin = User::create([
            'name' => 'Admin Unit SD', 'email' => 'admin-unit@example.com', 'phone' => '081111111112',
            'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        $this->student = Student::create([
            'nama_lengkap' => 'Siswa Uji', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id, 'entry_year_id' => $year->id, 'nis' => '000900',
        ]);
    }

    public function test_administrator_can_edit_a_student(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$this->student->ulid}", [
                'nama_lengkap' => 'Siswa Uji (Direvisi)',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertSame('Siswa Uji (Direvisi)', $this->student->fresh()->nama_lengkap);
        $this->assertSame('active', $this->student->fresh()->status);
    }

    public function test_administrator_can_delete_a_student(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/students/{$this->student->ulid}")
            ->assertOk();

        $this->assertSoftDeleted($this->student);
    }

    public function test_a_unit_admin_cannot_edit_or_delete_a_student(): void
    {
        $this->actingAs($this->unitAdmin)
            ->patchJson("/api/admin/students/{$this->student->ulid}", ['nama_lengkap' => 'Diubah Paksa'])
            ->assertForbidden();

        $this->actingAs($this->unitAdmin)
            ->deleteJson("/api/admin/students/{$this->student->ulid}")
            ->assertForbidden();

        $this->assertSame('Siswa Uji', $this->student->fresh()->nama_lengkap);
        $this->assertNull($this->student->fresh()->deleted_at);
    }

    public function test_editing_can_reassign_a_students_unit(): void
    {
        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/students/{$this->student->ulid}", ['school_unit_ulid' => $smp->ulid])
            ->assertOk();

        $this->assertSame($smp->id, $this->student->fresh()->school_unit_id);
    }

    public function test_deleting_a_student_keeps_their_bills_and_history_intact(): void
    {
        $feeType = \App\Models\FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $bill = \App\Models\Bill::create([
            'bill_number' => 'SPP/2026/08/00001', 'dedup_key' => 'spp:2026:08:'.$this->student->id,
            'description' => 'SPP Agustus', 'student_id' => $this->student->id,
            'academic_year_id' => AcademicYear::first()->id, 'fee_type_id' => $feeType->id,
            'subtotal' => 500000, 'total_amount' => 500000, 'remaining_amount' => 500000,
            'status' => 'unpaid', 'due_date' => now()->addDays(7)->toDateString(), 'issued_at' => now(),
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/admin/students/{$this->student->ulid}")->assertOk();

        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
    }
}
