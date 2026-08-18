<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The small pickers every admin form is built from - units, academic years,
 * classrooms. None of it is sensitive, but classrooms still go through the
 * same visibleTo() scope as everywhere else a classroom appears.
 */
class AdminReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_school_units_and_academic_years_are_visible_to_any_staff(): void
    {
        SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $admin = $this->staff('admin_unit', SchoolUnit::first());

        $this->actingAs($admin)->getJson('/api/admin/school-units')
            ->assertOk()->assertJsonCount(1, 'school_units');

        $this->actingAs($admin)->getJson('/api/admin/academic-years')
            ->assertOk()->assertJsonPath('academic_years.0.year', '2026/2027');
    }

    public function test_a_unit_admin_only_sees_their_own_units_classrooms(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);
        Classroom::create(['school_unit_id' => $smp->id, 'academic_year_id' => $year->id, 'tingkat' => 7, 'name' => '7A']);

        $admin = $this->staff('admin_unit', $sd);

        $classrooms = $this->actingAs($admin)->getJson('/api/admin/classrooms')->assertOk()->json('classrooms');

        $this->assertCount(1, $classrooms);
        $this->assertSame('1A', $classrooms[0]['name']);
    }

    public function test_a_central_admin_sees_every_units_classrooms(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);
        Classroom::create(['school_unit_id' => $smp->id, 'academic_year_id' => $year->id, 'tingkat' => 7, 'name' => '7A']);

        $admin = $this->staff('admin');

        $this->actingAs($admin)->getJson('/api/admin/classrooms')
            ->assertOk()->assertJsonCount(2, 'classrooms');
    }
}
