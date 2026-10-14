<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeAssignment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A semester has many assignments, not one "Tugas" column. Each assignment
 * is a gradebook column; grades.tugas - the figure rapor/watchlist/weighting
 * read - is kept as the average of a student's assignment scores.
 */
class GradeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;
    private AcademicYear $year;
    private Term $term;
    private Classroom $classroom;
    private Subject $subject;
    private User $guru;
    private Student $aisyah;
    private Student $faris;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SMP-T', 'label' => 'SMP Test', 'jenjang_group' => 'smp']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
        $this->term = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $this->classroom = Classroom::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id, 'name' => '7-A', 'tingkat' => 7]);
        $this->subject = Subject::create(['code' => 'MTK-'.uniqid(), 'name' => 'Matematika']);
        $this->guru = User::create([
            'name' => 'Bu Guru', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
        ]);
        ClassSchedule::create([
            'classroom_id' => $this->classroom->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $this->aisyah = $this->student('Aisyah', '70001');
        $this->faris = $this->student('Faris', '70002');
    }

    private function student(string $name, string $nis): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name, 'nis' => $nis, 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $this->classroom->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    private function base(): string
    {
        return "/api/guru/classrooms/{$this->classroom->ulid}/subjects/{$this->subject->ulid}";
    }

    private function addAssignment(string $title): string
    {
        return $this->actingAs($this->guru)
            ->postJson($this->base().'/assignments', ['title' => $title, 'assigned_on' => '2026-08-10'])
            ->assertCreated()
            ->json('assignment.ulid');
    }

    private function tugas(Student $student): ?float
    {
        $score = Grade::where('student_id', $student->id)->where('category', 'tugas')->value('score');

        return $score === null ? null : (float) $score;
    }

    public function test_tugas_is_the_average_of_the_assignment_scores(): void
    {
        $t1 = $this->addAssignment('Tugas 1');
        $t2 = $this->addAssignment('Tugas 2');

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t1 => 80, $t2 => 90], 'uts' => 75, 'uas' => 85],
            // Faris only has the first assignment so far: not counted as 0.
            ['student_ulid' => $this->faris->ulid, 'tugas' => [$t1 => 70]],
        ]])->assertOk();

        $this->assertSame(85.0, $this->tugas($this->aisyah));
        $this->assertSame(70.0, $this->tugas($this->faris));

        $roster = $this->actingAs($this->guru)->getJson($this->base().'/grades')->assertOk();
        $this->assertSame(['Tugas 1', 'Tugas 2'], array_column($roster->json('assignments'), 'title'));
        $aisyah = collect($roster->json('students'))->firstWhere('ulid', $this->aisyah->ulid);
        $this->assertEquals(90, $aisyah['tugas_scores'][$t2]);
        // 85*.2 + 75*.3 + 85*.5 = 82.0 with the default weights.
        $this->assertEquals(82.0, $aisyah['final']);
        $this->assertTrue($roster->json('editable'));
    }

    public function test_null_clears_a_cell_and_absent_keys_are_untouched(): void
    {
        $t1 = $this->addAssignment('Tugas 1');
        $t2 = $this->addAssignment('Tugas 2');

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t1 => 60, $t2 => 100], 'uts' => 70],
        ]])->assertOk();

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t2 => null]],
        ]])->assertOk();

        $this->assertSame(60.0, $this->tugas($this->aisyah));
        $this->assertSame(70.0, (float) Grade::where('student_id', $this->aisyah->id)->where('category', 'uts')->value('score'));
    }

    public function test_first_assignment_inherits_an_existing_single_tugas_score(): void
    {
        $this->actingAs($this->guru)->postJson($this->base().'/grades', [
            'category' => 'tugas', 'entries' => [['student_ulid' => $this->aisyah->ulid, 'score' => 77]],
        ])->assertCreated();

        $t1 = $this->addAssignment('Tugas 1');

        $this->assertEquals(77, GradeAssignment::where('ulid', $t1)->first()->scores()->value('score'));
        $this->assertSame(77.0, $this->tugas($this->aisyah));

        // From now on a directly typed tugas would be overwritten - refused.
        $this->actingAs($this->guru)->postJson($this->base().'/grades', [
            'category' => 'tugas', 'entries' => [['student_ulid' => $this->aisyah->ulid, 'score' => 10]],
        ])->assertStatus(422);
    }

    public function test_deleting_an_assignment_recomputes_the_average(): void
    {
        $t1 = $this->addAssignment('Tugas 1');
        $t2 = $this->addAssignment('Tugas 2');

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t1 => 50, $t2 => 100]],
            ['student_ulid' => $this->faris->ulid, 'tugas' => [$t2 => 90]],
        ]])->assertOk();

        $this->actingAs($this->guru)->deleteJson("/api/guru/grade-assignments/{$t2}")->assertOk();

        $this->assertSame(50.0, $this->tugas($this->aisyah));
        // Faris had nothing left - his tugas is empty again, not a stale 90.
        $this->assertNull($this->tugas($this->faris));
    }

    public function test_only_the_scheduled_teacher_may_touch_the_gradebook(): void
    {
        $t1 = $this->addAssignment('Tugas 1');
        $colleague = User::create([
            'name' => 'Pak Lain', 'email' => 'lain'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($colleague)->postJson($this->base().'/assignments', ['title' => 'X'])->assertForbidden();
        $this->actingAs($colleague)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t1 => 10]],
        ]])->assertForbidden();
        $this->actingAs($colleague)->deleteJson("/api/guru/grade-assignments/{$t1}")->assertForbidden();
    }

    public function test_out_of_range_and_foreign_students_are_rejected(): void
    {
        $t1 = $this->addAssignment('Tugas 1');

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $this->aisyah->ulid, 'tugas' => [$t1 => 101]],
        ]])->assertStatus(422);

        $other = Classroom::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id, 'name' => '7-B', 'tingkat' => 7]);
        $outsider = Student::create(['nama_lengkap' => 'Luar', 'nis' => '79999', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        Enrollment::create(['student_id' => $outsider->id, 'classroom_id' => $other->id, 'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01']);

        $this->actingAs($this->guru)->putJson($this->base().'/grades/sheet', ['entries' => [
            ['student_ulid' => $outsider->ulid, 'uts' => 80],
        ]])->assertStatus(422);
        $this->assertSame(0, Grade::count());
    }
}
