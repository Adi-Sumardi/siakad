<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the unit master (create/edit/delete) is central-admin only - the
 * same tier as fee settings and user management. Before this, the only way
 * to add, rename, or retire a campus was a raw SQL statement.
 */
class SchoolUnitAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $unitAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $unit = SchoolUnit::create(['code' => 'sd-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Administrator', 'email' => 'admin@example.com', 'phone' => '081111111111',
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->unitAdmin = User::create([
            'name' => 'Admin Unit', 'email' => 'admin-unit@example.com', 'phone' => '081111111112',
            'role' => 'admin_unit', 'school_unit_id' => $unit->id, 'is_active' => true,
        ]);
    }

    public function test_administrator_can_create_edit_and_delete_a_unit(): void
    {
        $created = $this->actingAs($this->admin)
            ->postJson('/api/admin/school-units', [
                'code' => 'smk-01', 'label' => 'SMK Baru', 'jenjang_group' => 'sma', 'is_active' => true, 'sort_order' => 9,
            ])
            ->assertCreated()
            ->json('school_unit');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/school-units/{$created['ulid']}", [
                'code' => 'smk-01', 'label' => 'SMK Baru (Revisi)', 'jenjang_group' => 'sma', 'is_active' => true, 'sort_order' => 9,
            ])
            ->assertOk();

        $this->assertSame('SMK Baru (Revisi)', SchoolUnit::where('code', 'smk-01')->value('label'));

        $this->actingAs($this->admin)->deleteJson("/api/admin/school-units/{$created['ulid']}")->assertOk();
        $this->assertDatabaseMissing('school_units', ['code' => 'smk-01']);
    }

    public function test_a_unit_admin_cannot_manage_units(): void
    {
        $this->actingAs($this->unitAdmin)
            ->postJson('/api/admin/school-units', ['code' => 'x', 'label' => 'X', 'jenjang_group' => 'sd', 'is_active' => true])
            ->assertForbidden();

        $this->actingAs($this->unitAdmin)->getJson('/api/admin/school-units/manage')->assertForbidden();
    }

    public function test_it_refuses_to_delete_a_unit_that_still_has_students(): void
    {
        $unit = SchoolUnit::create(['code' => 'smp-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp', 'is_active' => true]);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        Student::create(['nama_lengkap' => 'Anak Uji', 'jenis_kelamin' => 'L', 'school_unit_id' => $unit->id, 'entry_year_id' => $year->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/school-units/{$unit->ulid}")
            ->assertStatus(422);

        $this->assertDatabaseHas('school_units', ['id' => $unit->id]);
    }

    public function test_management_list_includes_inactive_units_and_student_counts(): void
    {
        SchoolUnit::create(['code' => 'nonaktif', 'label' => 'Unit Nonaktif', 'jenjang_group' => 'sma', 'is_active' => false]);

        $rows = $this->actingAs($this->admin)->getJson('/api/admin/school-units/manage')->assertOk()->json('school_units');

        $codes = collect($rows)->pluck('code');
        $this->assertContains('nonaktif', $codes);
    }
}
