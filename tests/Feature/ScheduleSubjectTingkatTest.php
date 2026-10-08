<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\SubjectTingkat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A period may only use a subject the classroom can take: from its unit,
 * active, and running in its tingkat. Clash rules themselves live in
 * AdminScheduleTest; the touch-but-not-overlap edge is repeated here for the
 * cross-class teacher case.
 */
class ScheduleSubjectTingkatTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $smp;

    private Classroom $c7;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->c7 = Classroom::create(['school_unit_id' => $this->smp->id, 'academic_year_id' => $year->id, 'name' => '7-A', 'tingkat' => 7]);
        $this->admin = User::create([
            'name' => 'Admin SMP', 'email' => 'a@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $this->smp->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function subject(array $tingkat, ?SchoolUnit $unit = null, array $inactive = []): Subject
    {
        $s = Subject::create(['school_unit_id' => ($unit ?? $this->smp)->id, 'name' => 'Mapel '.uniqid()]);
        foreach ($tingkat as $t) {
            SubjectTingkat::create(['subject_id' => $s->id, 'tingkat' => $t, 'is_active' => ! in_array($t, $inactive, true)]);
        }

        return $s;
    }

    private function store(Subject $subject, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/admin/classrooms/{$this->c7->ulid}/schedules", array_merge([
            'subject_ulid' => $subject->ulid, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ], $overrides));
    }

    public function test_a_subject_of_the_classrooms_tingkat_is_accepted(): void
    {
        $this->store($this->subject([7, 8, 9]))->assertCreated();
    }

    public function test_a_subject_of_another_tingkat_is_rejected(): void
    {
        $subject = $this->subject([8, 9]);

        $this->store($subject)
            ->assertStatus(422)
            ->assertJsonPath('message', "Mata pelajaran {$subject->name} tidak berlaku untuk tingkat 7 (kelas 7-A).");
    }

    public function test_a_tingkat_switched_off_for_the_subject_is_rejected(): void
    {
        $this->store($this->subject([7, 8], inactive: [7]))->assertStatus(422);
    }

    public function test_a_legacy_subject_without_tingkat_applies_everywhere(): void
    {
        $this->store($this->subject([]))->assertCreated();
    }

    public function test_a_subject_from_another_unit_is_rejected(): void
    {
        $sma = SchoolUnit::create(['code' => 'SMA-1', 'label' => 'SMA 1', 'jenjang_group' => 'sma']);

        $this->store($this->subject([7], $sma))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mata pelajaran tidak ditemukan untuk unit kelas ini.');
    }

    public function test_an_inactive_subject_is_rejected(): void
    {
        $subject = $this->subject([7]);
        $subject->update(['is_active' => false]);

        $this->store($subject)->assertStatus(422);
    }

    public function test_end_time_must_be_after_start_time_on_store_and_update(): void
    {
        $subject = $this->subject([7]);

        $this->store($subject, ['start_time' => '09:00', 'end_time' => '09:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_time' => 'Jam selesai harus lebih besar dari jam mulai.']);

        $schedule = ClassSchedule::create([
            'classroom_id' => $this->c7->id, 'subject_id' => $subject->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$this->c7->ulid}/schedules/{$schedule->ulid}", ['end_time' => '06:30'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jam selesai harus lebih besar dari jam mulai.');
    }

    public function test_a_teacher_clash_across_classes_names_the_other_class_and_touching_is_fine(): void
    {
        $guru = User::create([
            'name' => 'Pak Budi', 'email' => 'g@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->smp->id, 'is_active' => true, 'activated_at' => now(),
        ]);
        $c7b = Classroom::create(['school_unit_id' => $this->smp->id, 'academic_year_id' => $this->c7->academic_year_id, 'name' => '7-B', 'tingkat' => 7]);
        $subject = $this->subject([7]);
        ClassSchedule::create([
            'classroom_id' => $c7b->id, 'subject_id' => $subject->id, 'teacher_id' => $guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $this->store($subject, ['teacher_ulid' => $guru->ulid, 'start_time' => '07:30', 'end_time' => '08:30'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Guru Pak Budi sudah mengajar {$subject->name} di kelas 7-B pada jam yang sama (07:00-08:00).");

        $this->store($subject, ['teacher_ulid' => $guru->ulid, 'start_time' => '08:00', 'end_time' => '09:00'])->assertCreated();
    }
}
