<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Extracurricular;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuruProfileTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;
    private AcademicYear $year;
    private User $guru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SMP-T', 'label' => 'SMP Test', 'jenjang_group' => 'smp']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->guru = User::create([
            'name' => 'Bu Rina', 'email' => 'rina'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_profile_lists_this_years_responsibilities_only(): void
    {
        $prevYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $mine = Classroom::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id, 'name' => '7-A', 'tingkat' => 7, 'homeroom_teacher_id' => $this->guru->id]);
        $old = Classroom::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $prevYear->id, 'name' => '7-A', 'tingkat' => 7, 'homeroom_teacher_id' => $this->guru->id]);
        $mtk = Subject::create(['code' => 'MTK', 'name' => 'Matematika']);

        foreach ([[1, '07:00'], [3, '09:00']] as [$day, $start]) {
            ClassSchedule::create(['classroom_id' => $mine->id, 'subject_id' => $mtk->id, 'teacher_id' => $this->guru->id, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => '10:00']);
        }
        ClassSchedule::create(['classroom_id' => $old->id, 'subject_id' => $mtk->id, 'teacher_id' => $this->guru->id, 'day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '08:00']);

        Extracurricular::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id, 'name' => 'Robotik', 'pembina_id' => $this->guru->id, 'capacity' => 20, 'is_active' => true]);
        Extracurricular::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $prevYear->id, 'name' => 'Robotik', 'pembina_id' => $this->guru->id, 'capacity' => 20, 'is_active' => true]);

        $res = $this->actingAs($this->guru)->getJson('/api/guru/profile')->assertOk();

        $this->assertSame('SMP Test', $res->json('user.unit'));
        $this->assertSame([$mine->ulid], array_column($res->json('homeroom'), 'ulid'));
        $this->assertCount(1, $res->json('teaching'));
        $this->assertSame(2, $res->json('teaching.0.periods_per_week'));
        $this->assertSame([1, 3], array_column($res->json('weekly_schedule'), 'day_of_week'));
        $this->assertCount(1, $res->json('extracurriculars'));

        // Same year rule on the Ekskul Saya list.
        $this->assertCount(1, $this->actingAs($this->guru)->getJson('/api/guru/my-extracurriculars')->json('extracurriculars'));
    }

    public function test_teacher_can_fill_nip_and_jabatan_but_not_login_contacts(): void
    {
        $email = $this->guru->email;

        $this->actingAs($this->guru)->patchJson('/api/guru/profile', [
            'nip' => '19800101 200501 1 001', 'jabatan' => 'Guru Matematika', 'email' => 'lain@x.id',
        ])->assertOk()
            ->assertJsonPath('staff_profile.nip', '19800101 200501 1 001')
            ->assertJsonPath('staff_profile.jabatan', 'Guru Matematika');

        $this->assertSame($email, $this->guru->fresh()->email);

        $this->actingAs($this->guru)->patchJson('/api/guru/profile', ['nip' => 'abc'])->assertStatus(422);
    }

    public function test_other_roles_cannot_reach_it(): void
    {
        $wali = User::create(['name' => 'Wali', 'email' => 'w'.uniqid().'@x.id', 'role' => 'orangtua', 'is_active' => true, 'activated_at' => now()]);

        $this->actingAs($wali)->getJson('/api/guru/profile')->assertForbidden();
    }
}
