<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two coverage gaps the 2026-10-05 audit flagged: the point-threshold CRUD
 * had zero test references (its unit scoping is subtle - school-wide rows
 * belong to every unit's list), and so did the guru my-subjects listing
 * that the nilai screens depend on.
 */
class CoverageGapClosureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.uniqid().'@yapinet.id',
            'role' => $role,
            'school_unit_id' => $unit?->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_point_threshold_crud_scopes_school_wide_rows_per_unit(): void
    {
        $unitA = SchoolUnit::create(['code' => 'SD-T1', 'label' => 'SD Satu', 'jenjang_group' => 'sd']);
        $unitB = SchoolUnit::create(['code' => 'SD-T2', 'label' => 'SD Dua', 'jenjang_group' => 'sd']);

        // Central creates a SCHOOL-WIDE threshold (no unit)...
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/point-thresholds', [
                'min_points' => -100,
                'max_points' => 0,
                'label' => 'Perlu perhatian',
                'color' => 'red',
            ])->assertCreated();

        // ...and a unit-owned one for A.
        $owned = $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/point-thresholds', [
                'school_unit_code' => 'SD-T1',
                'min_points' => 1,
                'max_points' => 50,
                'label' => 'Baik',
            ])->assertCreated()->json('threshold.ulid');

        // A unit admin sees BOTH their own and the school-wide rows...
        $response = $this->actingAs($this->admin('admin_unit', $unitA))
            ->getJson('/api/admin/point-thresholds');
        $response->assertOk();
        $this->assertCount(2, $response->json('thresholds'));

        // ...and may edit their own...
        $this->actingAs($this->admin('admin_unit', $unitA))
            ->patchJson("/api/admin/point-thresholds/{$owned}", ['label' => 'Sangat baik'])
            ->assertOk()
            ->assertJsonPath('threshold.label', 'Sangat baik');

        // ...but another unit's threshold is "not found", never 403.
        $this->actingAs($this->admin('admin_unit', $unitB))
            ->patchJson("/api/admin/point-thresholds/{$owned}", ['label' => 'Menyusup'])
            ->assertStatus(404);
    }

    public function test_my_subjects_lists_each_teaching_assignment_once(): void
    {
        $unit = SchoolUnit::create(['code' => 'SD-T3', 'label' => 'SD Tiga', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);

        $classroom = Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $year->id,
            'tingkat' => 1,
            'name' => '1-T',
            'is_active' => true,
        ]);
        $math = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);
        $science = Subject::create(['code' => 'IPA', 'name' => 'IPA']);

        $guru = User::create([
            'name' => 'Guru Mapel',
            'email' => 'guru.mapel.'.uniqid().'@yapinet.id',
            'role' => 'guru',
            'school_unit_id' => $unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        // Two schedule slots for the SAME classroom+subject, plus a second
        // subject - the listing must dedupe the pair, not the subject.
        ClassSchedule::create(['classroom_id' => $classroom->id, 'subject_id' => $math->id, 'teacher_id' => $guru->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00']);
        ClassSchedule::create(['classroom_id' => $classroom->id, 'subject_id' => $math->id, 'teacher_id' => $guru->id, 'day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '08:00']);
        ClassSchedule::create(['classroom_id' => $classroom->id, 'subject_id' => $science->id, 'teacher_id' => $guru->id, 'day_of_week' => 3, 'start_time' => '07:00', 'end_time' => '08:00']);

        $response = $this->actingAs($guru)->getJson('/api/guru/my-subjects');

        $response->assertOk();
        $assignments = collect($response->json('assignments'));
        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments->contains(fn ($a) => $a['subject']['name'] === 'Matematika'));
        $this->assertTrue($assignments->contains(fn ($a) => $a['subject']['name'] === 'IPA'));

        // A teacher with no assignments sees an empty list, not an error.
        $other = User::create([
            'name' => 'Guru Kosong',
            'email' => 'guru.kosong.'.uniqid().'@yapinet.id',
            'role' => 'guru',
            'school_unit_id' => $unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($other)->getJson('/api/guru/my-subjects')->assertOk()->assertJsonCount(0, 'assignments');
    }
}
