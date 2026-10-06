<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Academic\ExtracurricularService;
use App\Services\Billing\BillGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membership is admin/pembina-assigned, not self-registered - so the ledger
 * side here is about not double-enrolling or overfilling a roster, not an
 * approval workflow. The billing half is the riskier one: 'ekskul' has
 * always billed every student unconditionally; this is the first thing that
 * gates it on actually being rostered somewhere.
 */
class ExtracurricularTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
    }

    private function ekskul(SchoolUnit $unit, array $overrides = []): Extracurricular
    {
        return Extracurricular::create(array_merge([
            'school_unit_id' => $unit->id, 'academic_year_id' => $this->year->id,
            'name' => 'Pramuka', 'is_active' => true,
        ], $overrides));
    }

    private function studentIn(SchoolUnit $unit, string $name = 'Aisyah Nur Ramadhani'): Student
    {
        return Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P',
            'school_unit_id' => $unit->id, 'status' => 'active',
        ]);
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function service(): ExtracurricularService
    {
        return app(ExtracurricularService::class);
    }

    public function test_an_admin_unit_creates_an_extracurricular_forced_into_their_own_unit(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $response = $this->actingAs($admin)->postJson('/api/admin/extracurriculars', [
            'name' => 'Futsal', 'academic_year_ulid' => $this->year->ulid,
            'school_unit_code' => $this->smp->code, // ignored - forced to their own unit
        ]);

        $response->assertStatus(201);
        $this->assertSame($this->sd->id, Extracurricular::first()->school_unit_id);
    }

    public function test_a_central_admin_can_create_a_school_wide_extracurricular_with_no_unit(): void
    {
        $admin = $this->staff('admin');

        $response = $this->actingAs($admin)->postJson('/api/admin/extracurriculars', [
            'name' => 'Paduan Suara', 'academic_year_ulid' => $this->year->ulid,
        ]);

        $response->assertStatus(201);
        $this->assertNull(Extracurricular::first()->school_unit_id);
    }

    public function test_assigning_an_already_active_member_is_rejected(): void
    {
        $ekskul = $this->ekskul($this->sd);
        $student = $this->studentIn($this->sd);
        $actor = $this->staff('admin', $this->sd);

        $this->service()->assignStudent($ekskul, $student, $actor);

        $this->expectException(\RuntimeException::class);
        $this->service()->assignStudent($ekskul, $student, $actor);
    }

    public function test_assigning_beyond_capacity_is_rejected(): void
    {
        $ekskul = $this->ekskul($this->sd, ['capacity' => 1]);
        $actor = $this->staff('admin', $this->sd);

        $this->service()->assignStudent($ekskul, $this->studentIn($this->sd, 'Siswa Satu'), $actor);

        $this->expectException(\RuntimeException::class);
        $this->service()->assignStudent($ekskul, $this->studentIn($this->sd, 'Siswa Dua'), $actor);
    }

    public function test_removing_a_member_marks_them_left_rather_than_deleting_the_row(): void
    {
        $ekskul = $this->ekskul($this->sd);
        $student = $this->studentIn($this->sd);
        $actor = $this->staff('admin', $this->sd);

        $member = $this->service()->assignStudent($ekskul, $student, $actor);
        $this->service()->removeStudent($member);

        $fresh = ExtracurricularMember::find($member->id);
        $this->assertNotNull($fresh);
        $this->assertSame('left', $fresh->status);
        $this->assertNotNull($fresh->left_on);
    }

    public function test_a_pembina_can_only_manage_their_own_extracurricular(): void
    {
        $pembina = $this->staff('guru', $this->sd);
        $otherPembina = $this->staff('guru', $this->sd);
        $ekskul = $this->ekskul($this->sd, ['pembina_id' => $pembina->id]);

        $this->actingAs($otherPembina)
            ->getJson("/api/guru/extracurriculars/{$ekskul->ulid}/members")
            ->assertStatus(404);

        $this->actingAs($pembina)
            ->getJson("/api/guru/extracurriculars/{$ekskul->ulid}/members")
            ->assertOk();
    }

    public function test_a_guardian_only_sees_their_own_childs_extracurriculars(): void
    {
        $ekskul = $this->ekskul($this->sd, ['name' => 'Robotik']);
        $mine = $this->studentIn($this->sd, 'Anak Saya');
        $theirs = $this->studentIn($this->sd, 'Anak Orang Lain');
        $actor = $this->staff('admin', $this->sd);

        $this->service()->assignStudent($ekskul, $mine, $actor);
        $this->service()->assignStudent($ekskul, $theirs, $actor);

        $guardian = User::create([
            'name' => 'Wali', 'email' => 'wali'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $g = Guardian::create(['user_id' => $guardian->id, 'nama' => 'Wali', 'hubungan' => 'ayah']);
        $mine->guardians()->attach($g->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $response = $this->actingAs($guardian)->getJson("/api/wali/students/{$mine->ulid}/extracurriculars");

        $response->assertOk();
        $response->assertJsonCount(1, 'extracurriculars');
        $response->assertJsonFragment(['name' => 'Robotik']);
    }

    public function test_bill_generator_skips_ekskul_for_a_student_with_no_active_membership(): void
    {
        $type = FeeType::create(['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term', 'requires_roster_membership' => true]);
        FeeRate::create(['fee_type_id' => $type->id, 'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id, 'amount' => 50000]);
        $this->studentIn($this->sd);

        $result = app(BillGenerator::class)->preview($type, $this->year, $this->sd);

        $this->assertSame(0, $result['eligible']);
        $this->assertSame('Belum terdaftar ekstrakurikuler', $result['skipped'][0]['reason']);
    }

    public function test_bill_generator_bills_ekskul_for_a_student_with_an_active_membership(): void
    {
        $type = FeeType::create(['code' => 'ekskul', 'name' => 'Ekstrakurikuler', 'recurrence' => 'per_term', 'requires_roster_membership' => true]);
        FeeRate::create(['fee_type_id' => $type->id, 'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id, 'amount' => 50000]);
        $student = $this->studentIn($this->sd);
        $ekskul = $this->ekskul($this->sd);
        $this->service()->assignStudent($ekskul, $student, $this->staff('admin', $this->sd));

        $result = app(BillGenerator::class)->preview($type, $this->year, $this->sd);

        $this->assertSame(1, $result['eligible']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_the_new_flag_does_not_affect_a_fee_type_that_does_not_require_roster_membership(): void
    {
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        FeeRate::create(['fee_type_id' => $spp->id, 'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id, 'amount' => 600000, 'due_day' => 10]);
        $this->studentIn($this->sd); // no extracurricular membership at all

        $result = app(BillGenerator::class)->preview($spp, $this->year, $this->sd, 7);

        $this->assertSame(1, $result['eligible']);
    }

    // --- Regression: bugs found and closed in the debugging pass -----------

    /**
     * A central admin could previously assign a student from one unit into
     * an ekskul scoped to a different unit with no check at all - neither
     * the controller nor the service compared the two.
     */
    public function test_a_student_cannot_be_assigned_to_a_unit_scoped_ekskul_in_a_different_unit(): void
    {
        $ekskul = $this->ekskul($this->smp); // scoped to SMP
        $sdStudent = $this->studentIn($this->sd);

        $this->expectException(\RuntimeException::class);
        $this->service()->assignStudent($ekskul, $sdStudent, $this->staff('admin'));
    }

    public function test_a_school_wide_ekskul_accepts_students_from_any_unit(): void
    {
        $schoolWide = Extracurricular::create([
            'school_unit_id' => null, 'academic_year_id' => $this->year->id,
            'name' => 'Paduan Suara', 'is_active' => true,
        ]);
        $smpStudent = $this->studentIn($this->smp);

        $member = $this->service()->assignStudent($schoolWide, $smpStudent, $this->staff('admin'));

        $this->assertSame('active', $member->status);
    }

    public function test_pembina_records_practice_and_predikat_that_reach_the_report(): void
    {
        $term = \App\Models\Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 9, 20, 10, 0, 0, 'Asia/Jakarta'));

        $pembina = $this->staff('guru', $this->sd);
        $ekskul = $this->ekskul($this->sd, ['pembina_id' => $pembina->id]);
        $student = $this->studentIn($this->sd);
        $member = $this->service()->assignStudent($ekskul, $student, $this->staff('admin'));

        foreach (['2026-09-05' => 'hadir', '2026-09-12' => 'alpa', '2026-09-19' => 'hadir'] as $date => $status) {
            $this->actingAs($pembina)->postJson("/api/guru/extracurriculars/{$ekskul->ulid}/meetings", [
                'date' => $date, 'records' => [['member_ulid' => $member->ulid, 'status' => $status]],
            ])->assertCreated();
        }

        // Future dates and non-members are refused.
        $this->actingAs($pembina)->postJson("/api/guru/extracurriculars/{$ekskul->ulid}/meetings", [
            'date' => '2026-09-30', 'records' => [['member_ulid' => $member->ulid, 'status' => 'hadir']],
        ])->assertStatus(422);

        $this->actingAs($pembina)->getJson("/api/guru/extracurriculars/{$ekskul->ulid}/meetings")
            ->assertOk()
            ->assertJsonPath("term_summary.{$member->ulid}.hadir", 2)
            ->assertJsonPath("term_summary.{$member->ulid}.alpa", 1);

        $this->actingAs($pembina)->putJson("/api/guru/extracurriculars/{$ekskul->ulid}/assessments", [
            'items' => [['member_ulid' => $member->ulid, 'predikat' => 'A', 'keterangan' => 'Aktif dan disiplin']],
        ])->assertOk();

        // Another teacher is not this activity's pembina: 404.
        $this->actingAs($this->staff('guru', $this->sd))->getJson("/api/guru/extracurriculars/{$ekskul->ulid}/assessments")
            ->assertNotFound();

        $report = app(\App\Services\Academic\ExtracurricularReport::class)->forStudent($student, $term);

        $this->assertSame('A', $report[0]['predikat']);
        $this->assertSame(2, $report[0]['hadir']);
        $this->assertSame(3, $report[0]['pertemuan']);

        \Illuminate\Support\Carbon::setTestNow();
    }
}
