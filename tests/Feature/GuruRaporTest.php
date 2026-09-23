<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The teacher's copy of the report card - the B.2 audit gap was that the
 * guardian could download a rapor while the teacher who wrote the grades in
 * it had no way to check it first. Must stream the same on-demand PDF as the
 * wali portal (nothing stored, D9), refuse another unit's student with 404,
 * and admit there is nothing to render without a term.
 */
class GuruRaporTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private Classroom $kelas;
    private Student $andi;
    private User $guru;
    private Subject $mtk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $this->kelas = Classroom::create(['school_unit_id' => $this->sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);

        $this->andi = Student::create([
            'school_unit_id' => $this->sd->id,
            'entry_year_id' => $year->id,
            'nama_lengkap' => 'Andi Pratama', 'jenis_kelamin' => 'L', 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $this->andi->id, 'classroom_id' => $this->kelas->id,
            'academic_year_id' => $year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        $this->guru = User::create([
            'name' => 'Guru SD', 'email' => 'guru'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->mtk = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK', 'name' => 'Matematika']);

        ClassSchedule::create([
            'classroom_id' => $this->kelas->id, 'subject_id' => $this->mtk->id, 'teacher_id' => $this->guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        foreach (['tugas' => 80, 'uts' => 70, 'uas' => 90] as $kategori => $skor) {
            Grade::create([
                'student_id' => $this->andi->id,
                'subject_id' => $this->mtk->id,
                'classroom_id' => $this->kelas->id,
                'term_id' => Term::first()->id,
                'category' => $kategori,
                'score' => $skor,
                'recorded_by' => $this->guru->id,
            ]);
        }
    }

    public function test_teacher_streams_the_same_pdf_the_guardian_gets(): void
    {
        $response = $this->actingAs($this->guru)
            ->get("/api/guru/students/{$this->andi->ulid}/rapor");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('Rapor-Andi-Pratama', $response->headers->get('Content-Disposition'));
    }

    public function test_another_units_student_is_a_404(): void
    {
        $siswaSmp = Student::create([
            'school_unit_id' => $this->smp->id,
            'entry_year_id' => AcademicYear::first()->id,
            'nama_lengkap' => 'Citra Lestari', 'jenis_kelamin' => 'P', 'status' => 'active',
        ]);

        $this->actingAs($this->guru)
            ->get("/api/guru/students/{$siswaSmp->ulid}/rapor")
            ->assertNotFound();
    }

    public function test_without_any_term_to_render_it_is_a_422(): void
    {
        Term::first()->update(['is_active' => false]);

        $this->actingAs($this->guru)
            ->get("/api/guru/students/{$this->andi->ulid}/rapor")
            ->assertStatus(422);
    }
}
