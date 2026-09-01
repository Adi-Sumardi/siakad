<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Academic\GradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grades are a plain upsert, not a revoke-ledger like point_records/
 * attendance_records - a teacher is always an authenticated, authorized
 * actor correcting their own entry. The one thing genuinely enforced here is
 * *who* that teacher is allowed to be: only the person class_schedules names
 * as this subject's teacher for this classroom, unlike points/attendance
 * where any teacher in the unit may act.
 */
class GradeTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->term = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
    }

    private function classroom(): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'name' => '1-A', 'tingkat' => 1,
        ]);
    }

    private function student(Classroom $classroom, string $name = 'Aisyah Nur Ramadhani', string $nis = '10001'): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name, 'nis' => $nis, 'jenis_kelamin' => 'P',
            'school_unit_id' => $classroom->school_unit_id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    private function guru(): User
    {
        return User::create([
            'name' => 'Bu Guru', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function subject(): Subject
    {
        return Subject::create(['code' => 'MTK-'.uniqid(), 'name' => 'Matematika']);
    }

    private function schedule(Classroom $classroom, Subject $subject, User $teacher): ClassSchedule
    {
        return ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);
    }

    /** A guardian with a login, holding the given student. */
    private function guardianFor(Student $student): User
    {
        $user = User::create([
            'name' => 'Budi Ramadhani', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id, 'nama' => 'Budi Ramadhani', 'hubungan' => 'ayah', 'email' => $user->email,
        ]);

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true,
        ]);

        return $user;
    }

    public function test_an_assigned_teacher_can_bulk_save_one_category_via_the_api(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom);

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 88]]],
        )->assertStatus(201)->assertJson(['recorded' => 1]);

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_id' => $subject->id, 'category' => 'tugas', 'score' => 88,
        ]);
    }

    public function test_a_teacher_not_assigned_to_this_subject_is_rejected_even_if_they_can_see_the_classroom(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $assignedTeacher = $this->guru();
        $this->schedule($classroom, $subject, $assignedTeacher);
        $student = $this->student($classroom);

        // Same unit, so this teacher can already see the classroom for
        // points/attendance - but was never given class_schedules.teacher_id
        // for this subject.
        $otherTeacher = $this->guru();

        $this->actingAs($otherTeacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 88]]],
        )->assertStatus(403);

        $this->assertDatabaseCount('grades', 0);
    }

    public function test_a_score_outside_zero_to_a_hundred_is_rejected(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom);

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'uas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 150]]],
        )->assertStatus(422);
    }

    public function test_re_saving_the_same_category_updates_the_row_instead_of_duplicating(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom);

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 70]]],
        );
        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 95]]],
        );

        $this->assertDatabaseCount('grades', 1);
        $this->assertDatabaseHas('grades', ['student_id' => $student->id, 'category' => 'tugas', 'score' => 95]);
    }

    public function test_final_score_is_null_until_all_three_categories_are_entered(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom);
        $service = app(GradeService::class);

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 80]]],
        );
        $this->assertNull($service->finalScore($student, $subject, $this->term));

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'uts', 'entries' => [['student_ulid' => $student->ulid, 'score' => 80]]],
        );
        $this->assertNull($service->finalScore($student, $subject, $this->term));
    }

    public function test_final_score_is_the_correct_weighted_sum_once_complete(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom);
        $service = app(GradeService::class);

        foreach (['tugas' => 80, 'uts' => 70, 'uas' => 90] as $category => $score) {
            $this->actingAs($teacher)->postJson(
                "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
                ['category' => $category, 'entries' => [['student_ulid' => $student->ulid, 'score' => $score]]],
            );
        }

        // 80*0.2 + 70*0.3 + 90*0.5 = 16 + 21 + 45 = 82
        $this->assertSame(82.0, $service->finalScore($student, $subject, $this->term));
    }

    public function test_a_guardian_only_sees_their_own_childs_grades(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom, nis: '10002');
        $otherStudent = $this->student($classroom, 'Anak Lain', '10003');

        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->getJson("/api/wali/students/{$student->ulid}/grades")->assertStatus(200);
        $this->actingAs($guardian)->getJson("/api/wali/students/{$otherStudent->ulid}/grades")->assertStatus(404);
    }

    public function test_the_rapor_endpoint_streams_a_real_pdf(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom, nis: '10004');
        $guardian = $this->guardianFor($student);

        foreach (['tugas' => 80, 'uts' => 70, 'uas' => 90] as $category => $score) {
            $this->actingAs($teacher)->postJson(
                "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
                ['category' => $category, 'entries' => [['student_ulid' => $student->ulid, 'score' => $score]]],
            );
        }

        $response = $this->actingAs($guardian)->get("/api/wali/students/{$student->ulid}/rapor");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_admin_unit_only_sees_their_own_units_grades_in_the_oversight_list(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom, nis: '10005');

        $otherUnit = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $otherClassroom = Classroom::create([
            'school_unit_id' => $otherUnit->id, 'academic_year_id' => $this->year->id, 'name' => '7-A', 'tingkat' => 7,
        ]);
        $otherSubject = $this->subject();
        $otherTeacher = User::create([
            'name' => 'Guru Lain', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $otherUnit->id, 'is_active' => true, 'activated_at' => now(),
        ]);
        $this->schedule($otherClassroom, $otherSubject, $otherTeacher);
        $otherStudent = $this->student($otherClassroom, 'Siswa Unit Lain', '20001');

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 88]]],
        );
        $this->actingAs($otherTeacher)->postJson(
            "/api/guru/classrooms/{$otherClassroom->ulid}/subjects/{$otherSubject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $otherStudent->ulid, 'score' => 70]]],
        );

        $unitAdmin = User::create([
            'name' => 'Admin Unit', 'email' => 'adminunit'.uniqid().'@yapinet.id',
            'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $response = $this->actingAs($unitAdmin)->getJson('/api/admin/grades')->assertStatus(200);
        $names = collect($response->json('grades'))->pluck('student.nama_lengkap');

        $this->assertTrue($names->contains('Aisyah Nur Ramadhani'));
        $this->assertFalse($names->contains('Siswa Unit Lain'));
    }

    public function test_grades_keep_their_original_subject_even_if_the_schedule_is_later_reassigned(): void
    {
        $classroom = $this->classroom();
        $subject = $this->subject();
        $teacher = $this->guru();
        $schedule = $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom, nis: '10006');

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 88]]],
        );

        // Reassign the schedule to a different teacher - the historical
        // grade row must still point at the same subject/classroom it was
        // actually recorded for.
        $newTeacher = $this->guru();
        $schedule->update(['teacher_id' => $newTeacher->id]);

        $grade = Grade::where('student_id', $student->id)->firstOrFail();
        $this->assertSame($subject->id, $grade->subject_id);
        $this->assertSame($classroom->id, $grade->classroom_id);
    }

    /**
     * Regression: a teacher authorized for (classroom A, subject X) used to
     * be able to submit a grade for a student who is actually enrolled in
     * classroom B, because the student lookup was unit-wide
     * (Student::visibleTo()) rather than scoped to classroom A's own
     * roster - silently attaching classroom A's classroom_id to what should
     * have been classroom B's grade.
     */
    public function test_a_teacher_cannot_grade_a_student_not_enrolled_in_the_classroom_they_are_authorized_for(): void
    {
        $subject = $this->subject();
        $classroomA = $this->classroom();
        $classroomB = Classroom::create([
            'school_unit_id' => $this->unit->id, 'academic_year_id' => $this->year->id,
            'name' => '1-B', 'tingkat' => 1,
        ]);

        $teacherA = $this->guru();
        $this->schedule($classroomA, $subject, $teacherA);

        $studentInB = $this->student($classroomB, 'Anak Kelas B', '10007');

        $response = $this->actingAs($teacherA)->postJson(
            "/api/guru/classrooms/{$classroomA->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'tugas', 'entries' => [['student_ulid' => $studentInB->ulid, 'score' => 90]]],
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('grades', ['student_id' => $studentInB->id]);
    }

    /**
     * Regression: rapor/grades used to hardcode Term::current(), so once a
     * new semester activated there was no way to reach a past one at all -
     * and summaryForRapor() used the student's CURRENT classroom regardless
     * of which term was asked for, which would have silently shown the
     * wrong subject list the moment a term selector existed.
     */
    public function test_a_past_terms_grades_stay_reachable_after_a_new_term_activates(): void
    {
        $subject = $this->subject();
        $classroom = $this->classroom();
        $teacher = $this->guru();
        $this->schedule($classroom, $subject, $teacher);
        $student = $this->student($classroom, nis: '10008');
        $guardian = $this->guardianFor($student);

        $this->actingAs($teacher)->postJson(
            "/api/guru/classrooms/{$classroom->ulid}/subjects/{$subject->ulid}/grades",
            ['category' => 'uas', 'entries' => [['student_ulid' => $student->ulid, 'score' => 77]]],
        );

        $oldTerm = $this->term;
        $newTerm = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'genap',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30', 'is_active' => true,
        ]);
        $oldTerm->update(['is_active' => false]);

        // Current-term view is now empty (nothing graded in the new term)...
        $current = $this->actingAs($guardian)->getJson("/api/wali/students/{$student->ulid}/grades");
        $current->assertOk();
        $this->assertSame('Genap 2026/2027', $current->json('term'));

        // ...but the old term's data is still reachable by ulid, still
        // pointing at the classroom the student was actually in then.
        $past = $this->actingAs($guardian)->getJson("/api/wali/students/{$student->ulid}/grades?term_ulid={$oldTerm->ulid}");
        $past->assertOk();
        $subjects = $past->json('subjects');
        $this->assertEquals(77.0, collect($subjects)->firstWhere('subject.ulid', $subject->ulid)['uas']);
    }
}
