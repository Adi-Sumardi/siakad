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

/**
 * The admin timetable's integrity rules (T25): a period that cannot actually
 * be taught must never be stored. An unknown teacher id used to silently
 * become a teacher-less period (which reads as "Bukan jadwal Anda" for every
 * guru on the day), and overlapping periods - for one classroom, or one
 * teacher across classrooms - used to store happily.
 */
class AdminScheduleTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private Classroom $classroom;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->admin = $this->staff('admin_unit', $this->sd);
        $this->classroom = Classroom::create([
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $year->id,
            'name' => '1-A', 'tingkat' => 1,
        ]);
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role).uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function subject(string $name = 'Pelajaran'): Subject
    {
        return Subject::create(['code' => 'SUB-'.uniqid(), 'name' => $name]);
    }

    private function schedule(array $overrides = []): ClassSchedule
    {
        return ClassSchedule::create(array_merge([
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject()->id,
            'teacher_id' => null,
            'day_of_week' => 1,
            'start_time' => '07:00',
            'end_time' => '08:30',
        ], $overrides));
    }

    private function store(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/admin/classrooms/{$this->classroom->ulid}/schedules", array_merge([
                'subject_ulid' => $this->subject()->ulid,
                'day_of_week' => 1,
                'start_time' => '07:00',
                'end_time' => '08:30',
            ], $overrides));
    }

    // --- Teacher resolution -------------------------------------------------

    public function test_an_unknown_teacher_ulid_is_rejected_instead_of_silently_unassigning(): void
    {
        $this->store(['teacher_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Guru tidak ditemukan atau bukan role guru.');

        $this->assertDatabaseCount('class_schedules', 0);
    }

    public function test_a_ulid_that_belongs_to_a_non_guru_is_rejected(): void
    {
        $orangtua = $this->staff('orangtua', $this->sd);

        $this->store(['teacher_ulid' => $orangtua->ulid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Guru tidak ditemukan atau bukan role guru.');

        $this->assertDatabaseCount('class_schedules', 0);
    }

    public function test_a_teacher_from_another_unit_is_rejected(): void
    {
        $guruSmp = $this->staff('guru', $this->smp);

        $this->store(['teacher_ulid' => $guruSmp->ulid])
            ->assertStatus(422)
            ->assertJsonPath('message', "Guru {$guruSmp->name} bukan dari unit kelas ini.");

        $this->assertDatabaseCount('class_schedules', 0);
    }

    public function test_a_period_without_a_teacher_stays_a_valid_explicit_choice(): void
    {
        $this->store()->assertStatus(201);

        $this->assertDatabaseCount('class_schedules', 1);
    }

    // --- Classroom overlaps -------------------------------------------------

    public function test_overlapping_periods_of_one_classroom_are_rejected_naming_the_clash(): void
    {
        $ipa = $this->subject('IPA');
        $this->schedule(['subject_id' => $ipa->id, 'start_time' => '07:00', 'end_time' => '08:30']);

        $this->store(['subject_ulid' => $this->subject('Matematika')->ulid, 'start_time' => '08:00', 'end_time' => '09:30'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bentrok dengan IPA (07:00-08:30) di kelas 1-A pada hari yang sama.');

        $this->assertDatabaseCount('class_schedules', 1);
    }

    public function test_back_to_back_periods_are_not_a_clash(): void
    {
        $this->schedule(['start_time' => '07:00', 'end_time' => '08:30']);

        $this->store(['start_time' => '08:30', 'end_time' => '10:00'])->assertStatus(201);

        $this->assertDatabaseCount('class_schedules', 2);
    }

    // --- Teacher double booking ----------------------------------------------

    public function test_a_teacher_cannot_be_booked_in_two_classrooms_at_once(): void
    {
        $guru = $this->staff('guru', $this->sd);
        $bindo = $this->subject('Bahasa Indonesia');
        $this->schedule(['subject_id' => $bindo->id, 'teacher_id' => $guru->id]);

        $otherRoom = Classroom::create([
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $this->classroom->academic_year_id,
            'name' => '2-B', 'tingkat' => 2,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/classrooms/{$otherRoom->ulid}/schedules", [
                'subject_ulid' => $this->subject()->ulid,
                'teacher_ulid' => $guru->ulid,
                'day_of_week' => 1,
                'start_time' => '08:00', 'end_time' => '09:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "Guru {$guru->name} sudah mengajar Bahasa Indonesia di kelas 1-A pada jam yang sama (07:00-08:30).",
            );

        // The same guru in the same window of another DAY is fine - the
        // clash is about the clock, not the workload.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/classrooms/{$otherRoom->ulid}/schedules", [
                'subject_ulid' => $this->subject()->ulid,
                'teacher_ulid' => $guru->ulid,
                'day_of_week' => 2,
                'start_time' => '08:00', 'end_time' => '09:30',
            ])->assertStatus(201);
    }

    // --- Update runs on the merged slot and never clashes with itself --------

    public function test_update_checks_the_merged_slot_and_ignores_the_schedule_itself(): void
    {
        $guru = $this->staff('guru', $this->sd);
        $matematika = $this->schedule(['subject_id' => $this->subject('Matematika')->id, 'start_time' => '07:00', 'end_time' => '08:30']);
        $ipa = $this->schedule(['subject_id' => $this->subject('IPA')->id, 'start_time' => '08:30', 'end_time' => '10:00']);

        // Extending the FIRST period into the second is a clash against IPA,
        // even though only end_time was sent.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$this->classroom->ulid}/schedules/{$matematika->ulid}", [
                'end_time' => '09:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bentrok dengan IPA (08:30-10:00) di kelas 1-A pada hari yang sama.');

        // Retagging the teacher only, with no time change, must not trip the
        // schedule against itself - and teaching the very next period is a
        // teacher's ordinary day, not a clash.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$this->classroom->ulid}/schedules/{$matematika->ulid}", [
                'teacher_ulid' => $guru->ulid,
            ])
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$this->classroom->ulid}/schedules/{$ipa->ulid}", [
                'teacher_ulid' => $guru->ulid,
            ])
            ->assertStatus(200);

        // The same guru cannot be claimed by an overlapping period in
        // another room, though.
        $otherRoom = Classroom::create([
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $this->classroom->academic_year_id,
            'name' => '2-B', 'tingkat' => 2,
        ]);
        $diKelasLain = ClassSchedule::create([
            'classroom_id' => $otherRoom->id, 'subject_id' => $this->subject('Seni')->id,
            'teacher_id' => null, 'day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '09:00',
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$otherRoom->ulid}/schedules/{$diKelasLain->ulid}", [
                'teacher_ulid' => $guru->ulid,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "Guru {$guru->name} sudah mengajar Matematika di kelas 1-A pada jam yang sama (07:00-08:30).",
            );
    }

    public function test_update_rejects_an_unknown_teacher_ulid_too(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/classrooms/{$this->classroom->ulid}/schedules/{$schedule->ulid}", [
                'teacher_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Guru tidak ditemukan atau bukan role guru.');

        $this->assertDatabaseHas('class_schedules', ['id' => $schedule->id, 'teacher_id' => null]);
    }

    // --- Scope ----------------------------------------------------------------

    public function test_another_units_classroom_is_a_404(): void
    {
        $smpRoom = Classroom::create([
            'school_unit_id' => $this->smp->id,
            'academic_year_id' => $this->classroom->academic_year_id,
            'name' => '7-A', 'tingkat' => 7,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/classrooms/{$smpRoom->ulid}/schedules", [
                'subject_ulid' => $this->subject()->ulid,
                'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:30',
            ])->assertStatus(404);
    }
}
