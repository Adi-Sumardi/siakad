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

    public function test_the_unit_jenjang_map_is_derived_from_live_classrooms_and_scoped(): void
    {
        // The cascading filters' ONE source (bug batch Poin 1-3): ladder
        // keys per unit, read off the classrooms that actually exist -
        // including the early-childhood name-prefix split - ladder-ordered,
        // inactive rows ignored, and an admin_unit's map clipped to their
        // own unit by the same visibleTo() as the classroom picker.
        $tk = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TK 13', 'jenjang_group' => 'tk']);
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        Classroom::create(['school_unit_id' => $tk->id, 'academic_year_id' => $year->id, 'tingkat' => 0, 'name' => 'TK-A 1']);
        Classroom::create(['school_unit_id' => $tk->id, 'academic_year_id' => $year->id, 'tingkat' => 0, 'name' => 'TK-B 2']);
        Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 2, 'name' => '2A']);
        Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);
        // Inactive rows contribute nothing - a retired structure must not
        // feed the dropdowns.
        Classroom::create(['school_unit_id' => $sd->id, 'academic_year_id' => $year->id, 'tingkat' => 6, 'name' => '6A', 'is_active' => false]);
        Classroom::create(['school_unit_id' => $smp->id, 'academic_year_id' => $year->id, 'tingkat' => 8, 'name' => '8 Ibnu Sina']);

        $map = $this->actingAs($this->staff('admin'))->getJson('/api/admin/unit-jenjang')->assertOk()->json('jenjang_by_unit');

        $this->assertSame(['tk-a', 'tk-b'], $map['TK-13']);
        // Insertion order (2 before 1) must not survive - ladder order does.
        $this->assertSame(['sd-1', 'sd-2'], $map['SD-SAKINAH']);
        $this->assertSame(['smp-8'], $map['SMP-SAKINAH']);

        $scoped = $this->actingAs($this->staff('admin_unit', $sd))->getJson('/api/admin/unit-jenjang')->assertOk()->json('jenjang_by_unit');
        $this->assertSame(['SD-SAKINAH'], array_keys($scoped));
    }
}
