<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * enrollments.status has carried promoted/repeated/left/graduated since the
 * very first migration; this is the first code that writes anything but
 * 'active' into it. Kenaikan kelas closes one enrollment row and, only for
 * outcomes that continue the student here, opens a fresh one - never an
 * UPDATE of classroom_id on the old row (docs/03-ERD.md).
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $currentYear;

    private AcademicYear $nextYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $this->currentYear = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->currentYear->activate();

        // 2027/2028 is already seeded by the seed-upcoming-academic-years
        // migration that RefreshDatabase runs - firstOrCreate avoids a
        // unique-constraint collision with it.
        $this->nextYear = AcademicYear::firstOrCreate(
            ['year' => '2027/2028'],
            ['starts_on' => '2027-07-01', 'ends_on' => '2028-06-30'],
        );
    }

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

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function service(): PromotionService
    {
        return app(PromotionService::class);
    }

    public function test_an_admin_unit_creates_a_classroom_forced_into_their_own_unit(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $response = $this->actingAs($admin)->postJson('/api/admin/classrooms', [
            'name' => '1-A', 'tingkat' => 1,
            'school_unit_code' => $this->smp->code, // ignored - forced to their own unit
            'academic_year_ulid' => $this->nextYear->ulid,
        ]);

        $response->assertStatus(201);
        $this->assertSame($this->sd->id, Classroom::first()->school_unit_id);
    }

    public function test_a_central_admin_creates_a_classroom_for_any_unit(): void
    {
        $admin = $this->staff('admin');

        $response = $this->actingAs($admin)->postJson('/api/admin/classrooms', [
            'name' => '7-A', 'tingkat' => 7,
            'school_unit_code' => $this->smp->code,
            'academic_year_ulid' => $this->nextYear->ulid,
        ]);

        $response->assertStatus(201);
        $this->assertSame($this->smp->id, Classroom::first()->school_unit_id);
    }

    public function test_a_central_admin_must_name_a_unit_when_creating_a_classroom(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->postJson('/api/admin/classrooms', [
            'name' => '7-A', 'tingkat' => 7, 'academic_year_ulid' => $this->nextYear->ulid,
        ])->assertStatus(422);
    }

    public function test_eligible_targets_for_promoted_only_returns_classrooms_one_tingkat_up_same_unit_first(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $ownUnitNext = $this->classroom($this->sd, $this->nextYear, 7, '7-A-SD'); // SD itself also runs tingkat 7
        $otherUnitNext = $this->classroom($this->smp, $this->nextYear, 7, '7-A-SMP');
        $wrongTingkat = $this->classroom($this->smp, $this->nextYear, 8, '8-A');

        $groups = $this->service()->eligibleTargetClassrooms($source, $this->nextYear, 'promoted');

        $this->assertCount(1, $groups['same_unit']);
        $this->assertSame($ownUnitNext->id, $groups['same_unit']->first()->id);
        $this->assertCount(1, $groups['other']);
        $this->assertSame($otherUnitNext->id, $groups['other']->first()->id);
    }

    public function test_promoting_a_student_closes_the_old_enrollment_and_opens_an_active_one_in_the_target(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $target = $this->classroom($this->smp, $this->nextYear, 7, '7-A');
        $student = $this->enrolledStudent($source);
        $actor = $this->staff('admin', $this->sd);

        $entries = collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);

        $old = Enrollment::where('student_id', $student->id)->where('academic_year_id', $this->currentYear->id)->first();
        $new = Enrollment::where('student_id', $student->id)->where('academic_year_id', $this->nextYear->id)->first();

        $this->assertSame('promoted', $old->status);
        $this->assertNotNull($old->left_on);
        $this->assertSame('active', $new->status);
        $this->assertSame($target->id, $new->classroom_id);
    }

    public function test_a_repeated_outcome_targets_the_same_tingkat_not_one_up(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 3, '3-A');
        $repeatTarget = $this->classroom($this->sd, $this->nextYear, 3, '3-A-Ulang');
        $student = $this->enrolledStudent($source);
        $actor = $this->staff('admin', $this->sd);

        $entries = collect([['student' => $student, 'outcome' => 'repeated', 'target_classroom' => $repeatTarget]]);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);

        $new = Enrollment::where('student_id', $student->id)->where('academic_year_id', $this->nextYear->id)->first();
        $this->assertSame(3, $new->classroom->tingkat);
    }

    public function test_graduated_and_left_close_the_enrollment_without_opening_a_new_one(): void
    {
        $sma = SchoolUnit::create(['code' => 'SMA-SAKINAH', 'label' => 'SMA Sakinah', 'jenjang_group' => 'sma']);
        $source = $this->classroom($sma, $this->currentYear, 12, '12-A');
        $graduate = $this->enrolledStudent($source, 'Lulusan');
        $leaver = $this->enrolledStudent($source, 'Pindah Sekolah');
        $actor = $this->staff('admin', $sma);

        $entries = collect([
            ['student' => $graduate, 'outcome' => 'graduated', 'target_classroom' => null],
            ['student' => $leaver, 'outcome' => 'left', 'target_classroom' => null],
        ]);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);

        $this->assertSame('graduated', Enrollment::where('student_id', $graduate->id)->first()->status);
        $this->assertSame('left', Enrollment::where('student_id', $leaver->id)->first()->status);
        $this->assertSame(0, Enrollment::where('academic_year_id', $this->nextYear->id)->count());
    }

    public function test_it_rejects_a_target_classroom_at_the_wrong_tingkat(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $wrongTarget = $this->classroom($this->smp, $this->nextYear, 8, '8-A'); // should be 7, not 8
        $student = $this->enrolledStudent($source);
        $actor = $this->staff('admin', $this->sd);

        $entries = collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $wrongTarget]]);

        $this->expectException(\RuntimeException::class);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);
    }

    public function test_it_rejects_a_student_whose_active_enrollment_is_not_actually_in_the_claimed_source_classroom(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $elsewhere = $this->classroom($this->sd, $this->currentYear, 5, '5-A');
        $target = $this->classroom($this->smp, $this->nextYear, 7, '7-A');
        $student = $this->enrolledStudent($elsewhere); // not actually in $source
        $actor = $this->staff('admin', $this->sd);

        $entries = collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]);

        $this->expectException(\RuntimeException::class);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);
    }

    public function test_it_rejects_a_student_who_already_has_an_enrollment_in_the_target_year(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $target = $this->classroom($this->smp, $this->nextYear, 7, '7-A');
        $student = $this->enrolledStudent($source);
        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $target->id,
            'academic_year_id' => $this->nextYear->id, 'status' => 'active', 'joined_on' => $this->nextYear->starts_on,
        ]);
        $actor = $this->staff('admin', $this->sd);

        $entries = collect([['student' => $student, 'outcome' => 'promoted', 'target_classroom' => $target]]);

        $this->expectException(\RuntimeException::class);
        $this->service()->promoteBatch($source, $this->nextYear, $entries, $actor);
    }

    public function test_an_admin_unit_cannot_promote_out_of_another_units_classroom_via_the_api(): void
    {
        $source = $this->classroom($this->smp, $this->currentYear, 7, '7-A');
        $admin = $this->staff('admin_unit', $this->sd); // different unit

        $this->actingAs($admin)
            ->getJson("/api/admin/classrooms/{$source->ulid}/promotion-roster")
            ->assertStatus(404);
    }

    public function test_the_promotion_endpoint_executes_a_full_batch(): void
    {
        $source = $this->classroom($this->sd, $this->currentYear, 6, '6-A');
        $target = $this->classroom($this->sd, $this->nextYear, 7, '7-A');
        $student = $this->enrolledStudent($source);
        $admin = $this->staff('admin', $this->sd);

        $response = $this->actingAs($admin)->postJson("/api/admin/classrooms/{$source->ulid}/promote", [
            'academic_year_ulid' => $this->nextYear->ulid,
            'entries' => [
                ['student_ulid' => $student->ulid, 'outcome' => 'promoted', 'target_classroom_ulid' => $target->ulid],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['promoted' => 1]);
        $this->assertSame('active', Enrollment::where('academic_year_id', $this->nextYear->id)->first()->status);
    }

    public function test_next_units_for_an_sd_returns_active_smp_units(): void
    {
        $inactiveSmp = SchoolUnit::create(['code' => 'SMP-X', 'label' => 'SMP Nonaktif', 'jenjang_group' => 'smp', 'is_active' => false]);

        $next = $this->sd->nextUnits();

        $this->assertTrue($next->pluck('id')->contains($this->smp->id));
        $this->assertFalse($next->pluck('id')->contains($inactiveSmp->id));
    }

    public function test_sma_has_no_next_unit(): void
    {
        $sma = SchoolUnit::create(['code' => 'SMA-SAKINAH', 'label' => 'SMA Sakinah', 'jenjang_group' => 'sma']);

        $this->assertTrue($sma->nextUnits()->isEmpty());
        $this->assertNull($sma->nextJenjangGroup());
    }
}
