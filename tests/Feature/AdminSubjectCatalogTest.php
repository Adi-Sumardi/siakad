<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The subject catalogue's write side (T30). Until now a subject could only
 * ever be created - a typo in the name was permanent, and the is_active
 * column the index already filtered on had no endpoint or UI to flip it.
 * Editing mirrors the point-rule catalogue (T3): the code stays locked, and
 * a subject with history (schedules, grades) is deactivated, never deleted.
 */
class AdminSubjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private Classroom $classroom;

    private User $central;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->central = $this->staff('admin');
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

    private function subject(?SchoolUnit $unit = null, string $name = 'Bahasaa Indonesia'): Subject
    {
        return Subject::create([
            'school_unit_id' => $unit?->id,
            'code' => 'SUB-'.uniqid(),
            'name' => $name,
        ]);
    }

    public function test_a_subject_can_be_renamed_and_deactivated_then_activated_again(): void
    {
        $subject = $this->subject($this->sd);

        // Rename - the actual pain point: a typo used to be permanent.
        $this->actingAs($this->central)->patchJson("/api/admin/subjects/{$subject->ulid}", [
            'name' => 'Bahasa Indonesia',
        ])->assertOk()->assertJsonPath('subject.name', 'Bahasa Indonesia');

        // Deactivate: disappears from the default catalogue...
        $this->actingAs($this->central)->patchJson("/api/admin/subjects/{$subject->ulid}", [
            'is_active' => false,
        ])->assertOk();

        $default = $this->actingAs($this->central)->getJson('/api/admin/subjects')->json('subjects');
        $this->assertNotContains($subject->ulid, array_column($default, 'ulid'));

        // ...but shows up flagged when the catalogue card opts in, and the
        // row is still addressable for the way back.
        $withInactive = $this->actingAs($this->central)
            ->getJson('/api/admin/subjects?include_inactive=1')
            ->json('subjects');
        $row = collect($withInactive)->firstWhere('ulid', $subject->ulid);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_active']);

        $this->actingAs($this->central)->patchJson("/api/admin/subjects/{$subject->ulid}", [
            'is_active' => true,
        ])->assertOk();

        $this->assertContains(
            $subject->ulid,
            array_column($this->actingAs($this->central)->getJson('/api/admin/subjects')->json('subjects'), 'ulid'),
        );
    }

    public function test_the_code_is_locked_on_edit(): void
    {
        $subject = $this->subject($this->sd, 'Matematika');

        $this->actingAs($this->central)->patchJson("/api/admin/subjects/{$subject->ulid}", [
            'code' => 'MATH-NEW',
            'name' => 'Matematika Umum',
        ])->assertOk();

        $this->assertSame($subject->code, $subject->fresh()->code);
        $this->assertSame('Matematika Umum', $subject->fresh()->name);
    }

    public function test_delete_is_refused_once_a_schedule_references_the_subject(): void
    {
        $subject = $this->subject($this->sd);
        ClassSchedule::create([
            'classroom_id' => $this->classroom->id, 'subject_id' => $subject->id, 'teacher_id' => null,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:30',
        ]);

        $this->actingAs($this->central)->deleteJson("/api/admin/subjects/{$subject->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mata pelajaran ini sudah terpakai di jadwal kelas. Nonaktifkan, bukan hapus.');

        $this->assertNotNull($subject->fresh());
    }

    public function test_delete_is_refused_once_a_grade_references_the_subject(): void
    {
        $subject = $this->subject($this->sd);

        $term = Term::create([
            'academic_year_id' => $this->classroom->academicYear->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Aisyah', 'nis' => '10001', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        Grade::create([
            'student_id' => $student->id, 'subject_id' => $subject->id,
            'classroom_id' => $this->classroom->id, 'term_id' => $term->id,
            'category' => 'tugas', 'score' => 90, 'recorded_by' => $this->central->id,
        ]);

        $this->actingAs($this->central)->deleteJson("/api/admin/subjects/{$subject->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mata pelajaran ini sudah punya nilai siswa. Nonaktifkan, bukan hapus.');
    }

    public function test_an_unused_subject_can_be_deleted(): void
    {
        $subject = $this->subject($this->sd);

        $this->actingAs($this->central)->deleteJson("/api/admin/subjects/{$subject->ulid}")
            ->assertOk()
            ->assertJsonPath('message', 'Mata pelajaran dihapus.');

        $this->assertNull($subject->fresh());
    }

    public function test_a_unit_admin_edits_only_their_own_units_subjects(): void
    {
        $unitAdmin = $this->staff('admin_unit', $this->sd);
        $ownSubject = $this->subject($this->sd);
        $otherUnitSubject = $this->subject($this->smp);
        $schoolWideSubject = $this->subject(null);

        $this->actingAs($unitAdmin)
            ->patchJson("/api/admin/subjects/{$ownSubject->ulid}", ['name' => 'Bahasa Indonesia'])
            ->assertOk();

        // Out of scope is a 404, not a 403 (R3) - school-wide subjects belong
        // to the central admin only, same split as point rules.
        $this->actingAs($unitAdmin)
            ->patchJson("/api/admin/subjects/{$otherUnitSubject->ulid}", ['name' => 'X'])
            ->assertNotFound();

        $this->actingAs($unitAdmin)
            ->patchJson("/api/admin/subjects/{$schoolWideSubject->ulid}", ['name' => 'X'])
            ->assertNotFound();

        $this->actingAs($unitAdmin)
            ->deleteJson("/api/admin/subjects/{$schoolWideSubject->ulid}")
            ->assertNotFound();
    }
}
