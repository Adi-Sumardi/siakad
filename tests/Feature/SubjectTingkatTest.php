<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectTingkat;
use App\Models\Term;
use App\Models\User;
use App\Services\Academic\SubjectMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One subject, many tingkat: the catalogue stores "Bahasa Indonesia" once
 * with its grade levels, and SubjectMerger folds the old per-tingkat
 * duplicates (IND7/IND8/IND9) into it without losing a schedule or grade.
 */
class SubjectTingkatTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $smp;

    private AcademicYear $year;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->admin = User::create([
            'name' => 'Admin SMP', 'email' => 'admin.smp@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $this->smp->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function classroom(int $tingkat, string $name): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $this->smp->id, 'academic_year_id' => $this->year->id,
            'name' => $name, 'tingkat' => $tingkat,
        ]);
    }

    private function schedule(Classroom $classroom, Subject $subject, int $day = 1): ClassSchedule
    {
        return ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id,
            'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);
    }

    public function test_a_subject_is_created_with_its_tingkat_and_no_code(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/subjects', ['name' => 'Bahasa Indonesia', 'tingkat' => [7, 8, 9]])
            ->assertCreated();

        $subject = Subject::where('name', 'Bahasa Indonesia')->firstOrFail();
        $this->assertNull($subject->code);
        $this->assertSame([7, 8, 9], $subject->tingkatRows->pluck('tingkat')->all());

        $this->actingAs($this->admin)->getJson('/api/admin/subjects')
            ->assertJsonCount(1, 'subjects')
            ->assertJsonPath('subjects.0.tingkat.1.tingkat', 8);
    }

    public function test_a_second_subject_with_the_same_name_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/subjects', ['name' => 'Matematika', 'tingkat' => [7]])->assertCreated();

        $this->actingAs($this->admin)->postJson('/api/admin/subjects', ['name' => ' matematika ', 'tingkat' => [8]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mata pelajaran Matematika sudah ada. Tambahkan tingkatnya lewat tombol Edit.');
    }

    public function test_tingkat_is_required(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/subjects', ['name' => 'IPA', 'tingkat' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tingkat');
    }

    public function test_dropping_a_scheduled_tingkat_deactivates_it_and_an_unused_one_is_removed(): void
    {
        $subject = Subject::create(['school_unit_id' => $this->smp->id, 'name' => 'IPA']);
        foreach ([7, 8, 9] as $t) {
            SubjectTingkat::create(['subject_id' => $subject->id, 'tingkat' => $t]);
        }
        $this->schedule($this->classroom(8, '8-A'), $subject);

        $this->actingAs($this->admin)->patchJson("/api/admin/subjects/{$subject->ulid}", ['tingkat' => [7]])->assertOk();

        $rows = $subject->tingkatRows()->get()->keyBy('tingkat');
        $this->assertTrue($rows[7]->is_active);
        $this->assertFalse($rows[8]->is_active);
        $this->assertFalse($rows->has(9));
        $this->assertFalse($subject->fresh()->appliesToTingkat(8));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $ind7 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND7', 'name' => 'Bahasa Indonesia']);
        $ind8 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND8', 'name' => 'Bahasa Indonesia']);

        $plan = app(SubjectMerger::class)->plan();

        $this->assertCount(1, $plan);
        $this->assertSame($ind7->id, $plan[0]['survivor']['id']);
        $this->assertSame([7, 8], $plan[0]['tingkat']);
        $this->assertNull($ind8->fresh()->merged_into_id);
        $this->assertSame(0, SubjectTingkat::count());
    }

    public function test_merge_repoints_schedules_and_grades_and_is_idempotent_and_reversible(): void
    {
        $c7 = $this->classroom(7, '7-A');
        $c8 = $this->classroom(8, '8-A');
        $c9 = $this->classroom(9, '9-A');

        $ind7 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND7', 'name' => 'Bahasa Indonesia']);
        $ind8 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND8', 'name' => 'Bahasa Indonesia']);
        $ind9 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND9', 'name' => 'bahasa indonesia ']);
        $other = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'MTK', 'name' => 'Matematika']);

        $this->schedule($c7, $ind7);
        $this->schedule($c8, $ind8);
        $s9a = $this->schedule($c9, $ind9, 1);
        $s9b = $this->schedule($c9, $ind9, 2);
        $this->schedule($c9, $other, 3);

        $term = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
        $student = Student::create([
            'nama_lengkap' => 'Aisyah', 'nis' => '10001', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp->id, 'status' => 'active',
        ]);
        $grade = Grade::create([
            'student_id' => $student->id, 'subject_id' => $ind8->id, 'classroom_id' => $c8->id,
            'term_id' => $term->id, 'category' => 'tugas', 'score' => 90, 'recorded_by' => $this->admin->id,
        ]);

        $merger = app(SubjectMerger::class);
        $merger->apply();

        // IND9 has the most schedules, so it survives.
        $this->assertSame(4, ClassSchedule::where('subject_id', $ind9->id)->count());
        $this->assertSame($ind9->id, $grade->fresh()->subject_id);
        $this->assertSame($ind9->id, $ind7->fresh()->merged_into_id);
        $this->assertFalse($ind8->fresh()->is_active);
        $this->assertNotNull($ind8->fresh()->code, 'the old code is kept, never deleted');
        $this->assertSame([7, 8, 9], $ind9->tingkatRows()->pluck('tingkat')->all());
        $this->assertSame([9], $other->tingkatRows()->pluck('tingkat')->all());
        $this->assertSame(5, ClassSchedule::count());

        $this->actingAs($this->admin)->getJson('/api/admin/subjects?include_inactive=1')
            ->assertJsonCount(2, 'subjects');

        // Second run: nothing left to merge, nothing changes.
        $this->assertSame([], $merger->plan());
        $logCount = \DB::table('subject_merge_log')->count();
        $merger->apply();
        $this->assertSame($logCount, \DB::table('subject_merge_log')->count());

        $merger->rollback();

        $this->assertSame($ind8->id, $grade->fresh()->subject_id);
        $this->assertSame(1, ClassSchedule::where('subject_id', $ind7->id)->count());
        $this->assertSame(2, ClassSchedule::where('subject_id', $ind9->id)->count());
        $this->assertSame($s9a->id, ClassSchedule::where('subject_id', $ind9->id)->orderBy('id')->first()->id);
        $this->assertNull($ind7->fresh()->merged_into_id);
        $this->assertTrue($ind8->fresh()->is_active);
        $this->assertSame(0, SubjectTingkat::count());
        $this->assertNotNull($s9b->fresh());
    }

    public function test_a_group_whose_grades_would_collide_is_skipped(): void
    {
        $c7 = $this->classroom(7, '7-A');
        $ind7 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND7', 'name' => 'Bahasa Indonesia']);
        $ind8 = Subject::create(['school_unit_id' => $this->smp->id, 'code' => 'IND8', 'name' => 'Bahasa Indonesia']);

        $term = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
        $student = Student::create([
            'nama_lengkap' => 'Aisyah', 'nis' => '10001', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp->id, 'status' => 'active',
        ]);
        foreach ([$ind7, $ind8] as $s) {
            Grade::create([
                'student_id' => $student->id, 'subject_id' => $s->id, 'classroom_id' => $c7->id,
                'term_id' => $term->id, 'category' => 'tugas', 'score' => 80, 'recorded_by' => $this->admin->id,
            ]);
        }

        $plan = app(SubjectMerger::class)->apply();

        $this->assertNotNull($plan[0]['skipped']);
        $this->assertNull($ind8->fresh()->merged_into_id);
        $this->assertSame(2, Grade::count());
    }
}
