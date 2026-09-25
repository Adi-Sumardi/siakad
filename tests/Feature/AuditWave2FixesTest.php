<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Academic\PromotionService;
use App\Services\Academic\RaporPdfService;
use App\Services\Academic\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * Gelombang 2 dari audit fungsional 2026-09-21: the annual cycle. The
 * semester flip becomes a one-click operation that leaves exactly one term
 * lit; the dashboard reads the same "current semester" the write lanes file
 * under; grade-drop detection compares the immediately preceding term across
 * year boundaries; a historical rapor names that year's class; and the
 * kindergarten promotion chain (RA -> TK -> SD) works because crossing a
 * jenjang lands on the destination's entry rung instead of source+1.
 */
class AuditWave2FixesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private AcademicYear $year;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function term(AcademicYear $year, string $name, string $startsOn, bool $active = false): Term
    {
        return Term::create([
            'academic_year_id' => $year->id,
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => date('Y-m-d', strtotime($startsOn.' +6 months -1 day')),
            'is_active' => $active,
        ]);
    }

    // ---- Semester management -------------------------------------------------

    public function test_a_term_can_be_created_and_activated_from_the_api(): void
    {
        $this->term($this->year, 'ganjil', '2026-07-01', true);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/terms', [
            'academic_year_ulid' => $this->year->ulid,
            'name' => 'genap',
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-06-30',
        ]);
        $response->assertCreated();

        $genap = Term::where('name', 'genap')->first();
        $this->assertFalse((bool) $genap->is_active, 'semester baru lahir tidak aktif');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/terms/{$genap->ulid}/activate")
            ->assertOk();

        $this->assertTrue((bool) $genap->fresh()->is_active);
        $this->assertSame('genap', Term::current()?->name, 'setelah aktivasi, semester aktif = yang baru');
        $this->assertSame(1, Term::where('is_active', true)->count(), 'hanya satu semester boleh menyala');
    }

    public function test_term_management_is_central_only(): void
    {
        $unitAdmin = User::create([
            'name' => 'Admin Unit', 'email' => 'unit'.uniqid().'@yapinet.id',
            'role' => 'admin_unit', 'school_unit_id' => $this->sd->id,
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($unitAdmin)->postJson('/api/admin/terms', [
            'academic_year_ulid' => $this->year->ulid,
            'name' => 'genap',
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-06-30',
        ])->assertStatus(403);
    }

    public function test_activating_a_new_academic_year_closes_the_old_years_terms(): void
    {
        $this->term($this->year, 'genap', '2027-01-04', true);
        $this->assertSame('genap', Term::current()?->name);

        // 2027/2028 is already seeded by the seed-upcoming-academic-years
        // migration RefreshDatabase runs - firstOrCreate avoids the collision.
        $newYear = AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'],
        );
        $newYear->activate();

        $this->assertNull(Term::current(), 'tahun berganti tanpa semester baru = jujur kosong, bukan diam-diam semester lama');
        $this->assertFalse((bool) Term::where('name', 'genap')->first()->is_active);
    }

    public function test_term_current_prefers_the_latest_start_when_two_rows_are_lit(): void
    {
        // Two lit terms are now IMPOSSIBLE by engine (audit T47's partial
        // unique index) - this test used to manufacture exactly that bad
        // data to check the tiebreak. The invariant it guarded moved from
        // "the pick is defensible" to "the data cannot exist": the second
        // lit row is refused by the database itself, and the honest
        // ordering (latest starts_on) is enforced in Term::current().
        $this->term($this->year, 'ganjil', '2026-07-01', true);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->term($this->year, 'genap', '2027-01-04', true);
    }

    // ---- One definition / previous term --------------------------------------

    public function test_previous_term_crosses_the_year_boundary_to_the_term_directly_before(): void
    {
        $oldYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->term($oldYear, 'ganjil', '2025-07-01');
        $this->term($oldYear, 'genap', '2026-01-05');
        $ganjilNew = $this->term($this->year, 'ganjil', '2026-07-01', true);

        $prev = app(WatchlistService::class)->previousTerm($ganjilNew);

        // The old cross-year branch matched by name first and returned
        // ganjil 2025/2026 - a full year back, skipping genap in between.
        $this->assertSame('genap', $prev?->name);
        $this->assertSame($oldYear->id, $prev->academic_year_id);
    }

    // ---- Historical rapor -----------------------------------------------------

    public function test_rapor_for_a_past_term_names_that_years_classroom(): void
    {
        $oldYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $oldTerm = $this->term($oldYear, 'genap', '2026-01-05');
        $newTerm = $this->term($this->year, 'ganjil', '2026-07-01', true);

        $oldClass = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $oldYear->id,
            'tingkat' => 7, 'name' => '7-A', 'is_active' => true,
        ]);
        $newClass = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id,
            'tingkat' => 8, 'name' => '8-A', 'is_active' => true,
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Aisyah Nur Ramadhani', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $oldClass->id,
            'academic_year_id' => $oldYear->id, 'status' => 'promoted', 'joined_on' => '2025-07-15',
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $newClass->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-15',
        ]);

        $rapor = app(RaporPdfService::class);

        $this->assertSame('7-A', $rapor->classroomNameFor($student, $oldTerm), 'rapor semester lalu memakai kelas tahun itu');
        $this->assertSame('8-A', $rapor->classroomNameFor($student, $newTerm));
    }

    // ---- Promotion package ----------------------------------------------------

    private function classroom(SchoolUnit $unit, AcademicYear $year, int $tingkat, string $name): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $unit->id, 'academic_year_id' => $year->id,
            'tingkat' => $tingkat, 'name' => $name, 'is_active' => true,
        ]);
    }

    private function enrolledStudent(Classroom $classroom, string $name = 'Aisyah Nur Ramadhani'): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P',
            'school_unit_id' => $classroom->school_unit_id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $classroom->academic_year_id,
            'status' => 'active', 'joined_on' => $classroom->academicYear->starts_on,
        ]);

        return $student;
    }

    public function test_promotion_rejects_a_target_year_that_is_not_after_the_source_year(): void
    {
        $olderYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $source = $this->classroom($this->sd, $this->year, 7, '7-A');
        $student = $this->enrolledStudent($source);
        $olderTarget = $this->classroom($this->sd, $olderYear, 8, '8-A');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus SETELAH tahun ajaran sumber');

        app(PromotionService::class)->promoteBatch(
            $source,
            $olderYear,
            collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $olderTarget]]),
            $this->admin,
        );
    }

    public function test_an_ra_graduate_can_continue_into_tk_at_the_entry_rung(): void
    {
        $ra = SchoolUnit::create(['code' => 'RA-SAKINAH', 'label' => 'RA Sakinah', 'jenjang_group' => 'ra']);
        $tk = SchoolUnit::create(['code' => 'TK-SAKINAH', 'label' => 'TK Sakinah', 'jenjang_group' => 'tk']);
        $nextYear = AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'],
        );

        // RA's kelompok B sits at rung 0; TK's kelompok A is also rung 0.
        // The old source+1 arithmetic looked for tingkat 1 in TK units and
        // found nothing - and (with RA mislabeled 'tk') happily offered SD 1.
        $source = $this->classroom($ra, $this->year, 0, 'Kelompok B');
        $tkA = $this->classroom($tk, $nextYear, 0, 'TK-A');
        $student = $this->enrolledStudent($source);

        $targets = app(PromotionService::class)->eligibleTargetClassrooms($source, $nextYear, 'promoted');

        $this->assertTrue($targets['other']->pluck('id')->contains($tkA->id), 'TK-A ditawarkan sebagai tujuan lanjut RA');

        app(PromotionService::class)->promoteBatch(
            $source,
            $nextYear,
            collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $tkA]]),
            $this->admin,
        );

        $this->assertSame(
            $tkA->id,
            Enrollment::where('student_id', $student->id)->where('academic_year_id', $nextYear->id)->first()->classroom_id,
        );
    }

    public function test_a_tk_kelompok_b_student_moves_to_sd_grade_one(): void
    {
        $tk = SchoolUnit::create(['code' => 'TK-SAKINAH', 'label' => 'TK Sakinah', 'jenjang_group' => 'tk']);
        $nextYear = AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'],
        );

        // TK kelompok B is rung 1 INSIDE tk; crossing to SD must land on SD's
        // entry rung (1), not on rung 2.
        $source = $this->classroom($tk, $this->year, 1, 'TK-B');
        $sd1 = $this->classroom($this->sd, $nextYear, 1, '1-A');
        $this->classroom($this->sd, $nextYear, 2, '2-A');
        $student = $this->enrolledStudent($source);

        $targets = app(PromotionService::class)->eligibleTargetClassrooms($source, $nextYear, 'promoted');

        $ids = $targets['other']->pluck('id');
        $this->assertTrue($ids->contains($sd1->id));
        $this->assertFalse($ids->contains(Classroom::where('name', '2-A')->first()->id), 'tingkat 2 tidak boleh ditawarkan');
    }

    public function test_import_gives_kindergarten_classes_tingkat_zero(): void
    {
        $tk = SchoolUnit::create(['code' => 'TK-SAKINAH', 'label' => 'TK Sakinah', 'jenjang_group' => 'tk']);

        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code,kelas\n".
            "Aisyah Kecil,27001,P,tk,TK-A\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $this->actingAs($this->admin)->postJson('/api/admin/import/students', ['file' => $file])
            ->assertOk();

        $classroom = Classroom::where('school_unit_id', $tk->id)->where('name', 'TK-A')->first();

        $this->assertNotNull($classroom);
        $this->assertSame(0, (int) $classroom->tingkat, 'kelas TK tanpa angka diberi tingkat 0 (antrian tangga), bukan null');
    }
}
