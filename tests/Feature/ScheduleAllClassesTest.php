<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "Semua kelas" timetable: every visible period with its classroom, still scoped to the caller's unit. */
class ScheduleAllClassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_unit_admin_sees_every_class_of_their_own_unit_only(): void
    {
        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);
        $sma = SchoolUnit::create(['code' => 'SMA-1', 'label' => 'SMA 1', 'jenjang_group' => 'sma']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $subject = Subject::create(['name' => 'Matematika']);
        $make = function (SchoolUnit $unit, string $name) use ($year, $subject) {
            $c = Classroom::create(['school_unit_id' => $unit->id, 'academic_year_id' => $year->id, 'name' => $name, 'tingkat' => 7]);
            ClassSchedule::create([
                'classroom_id' => $c->id, 'subject_id' => $subject->id,
                'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
            ]);
        };
        $make($smp, '7-A');
        $make($smp, '7-B');
        $make($sma, '10-A');

        // Last year's 7-A keeps its schedule on file but is not this year's timetable.
        $oldYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $old = Classroom::create(['school_unit_id' => $smp->id, 'academic_year_id' => $oldYear->id, 'name' => '7-A', 'tingkat' => 7]);
        ClassSchedule::create([
            'classroom_id' => $old->id, 'subject_id' => $subject->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $admin = User::create([
            'name' => 'Admin SMP', 'email' => 'a@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $smp->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/schedules')->assertOk();

        $this->assertEqualsCanonicalizing(['7-A', '7-B'], collect($response->json('schedules'))->pluck('classroom.name')->all());
        $response->assertJsonPath('schedules.0.start_time', '07:00');

        $central = User::create([
            'name' => 'Pusat', 'email' => 'p@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);
        $this->actingAs($central)->getJson('/api/admin/schedules')->assertJsonCount(3, 'schedules');
        $this->actingAs($central)->getJson('/api/admin/schedules?unit=SMA-1')
            ->assertJsonCount(1, 'schedules')
            ->assertJsonPath('schedules.0.classroom.name', '10-A');
    }
}
