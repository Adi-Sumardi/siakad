<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Self-service extracurricular enrolment (decision 2026-09-09: parents may
 * register their own children).
 *
 * The parent picks from a list the API curates - this year's active
 * activities, the child's unit, room left, not already joined - and the
 * enrolment itself goes through the same ExtracurricularService an admin
 * assign uses, so unit match, capacity and the no-double-enrol rule cannot
 * drift between the two doors.
 */
class WaliExtracurricularEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private SchoolUnit $otherUnit;

    private AcademicYear $year;

    private Student $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13 Rawamangun', 'jenjang_group' => 'sd']);
        $this->otherUnit = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->parent = User::create([
            'name' => 'Wali Ananda', 'email' => 'wali@example.com', 'role' => 'orangtua', 'is_active' => true,
        ]);

        $guardian = Guardian::create(['user_id' => $this->parent->id, 'nama' => 'Wali Ananda', 'hubungan' => 'ayah']);

        $this->student = Student::create([
            'nama_lengkap' => 'Ananda Ekskul', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);
        $guardian->students()->attach($this->student->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);
    }

    private function ekskul(array $overrides = []): Extracurricular
    {
        return Extracurricular::create($overrides + [
            'name' => 'Futsal',
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'capacity' => null,
            'is_active' => true,
        ]);
    }

    public function test_the_available_list_is_curated_for_the_child(): void
    {
        $open = $this->ekskul(['name' => 'Futsal']);
        $this->ekskul(['name' => 'Unit Lain', 'school_unit_id' => $this->otherUnit->id]);
        $this->ekskul(['name' => 'Dinonaktifkan', 'is_active' => false]);

        $oldYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->ekskul(['name' => 'Tahun Lalu', 'academic_year_id' => $oldYear->id]);

        $full = $this->ekskul(['name' => 'Mewarnai', 'capacity' => 1]);
        ExtracurricularMember::create([
            'extracurricular_id' => $full->id,
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'status' => 'active',
            'joined_on' => now()->toDateString(),
        ]);

        $res = $this->actingAs($this->parent)->getJson("/api/wali/students/{$this->student->ulid}/extracurriculars");

        $res->assertOk();

        // Joined activities stay on the main list, not the pickable one.
        $this->assertSame(['Futsal'], $res->json('available.*.name'), 'Hanya ekskul unit & tahun aktif & ber-ruang & belum diikuti yang boleh dipilih');
        $this->assertSame(['Mewarnai'], $res->json('extracurriculars.*.name'));
    }

    public function test_a_parent_can_enroll_their_child(): void
    {
        $ekskul = $this->ekskul(['name' => 'Robotik', 'capacity' => 2]);

        $res = $this->actingAs($this->parent)->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", [
            'extracurricular_ulid' => $ekskul->ulid,
        ]);

        $res->assertCreated();

        $member = ExtracurricularMember::sole();
        $this->assertSame('active', $member->status);
        $this->assertSame($this->parent->id, $member->assigned_by, 'Actor tercatat: wali yang mendaftarkan');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'extracurricular.self_enrolled',
            'user_id' => $this->parent->id,
        ]);
    }

    public function test_enrolment_reuses_the_admin_service_rules(): void
    {
        $ekskul = $this->ekskul(['name' => 'Panahan', 'capacity' => 1]);

        // Fill the single slot with another child first.
        $other = Student::create(['nama_lengkap' => 'Siswa Lain', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        ExtracurricularMember::create([
            'extracurricular_id' => $ekskul->id, 'student_id' => $other->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", ['extracurricular_ulid' => $ekskul->ulid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Panahan sudah penuh (1 siswa).');

        // Cross-unit through the parent door gets the service's unit-match
        // refusal, not a silent enrolment.
        $foreign = $this->ekskul(['name' => 'Basket SMP', 'school_unit_id' => $this->otherUnit->id]);
        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", ['extracurricular_ulid' => $foreign->ulid])
            ->assertStatus(422);

        // A second enrolment of the same child into the same activity is a
        // double-enrol, even from the parent's own portal.
        ExtracurricularMember::create([
            'extracurricular_id' => $foreign->id, 'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => now()->toDateString(),
        ]);
        // (reset unit so the only remaining refusal can be the double-enrol)
        $foreign->forceFill(['school_unit_id' => $this->unit->id])->save();
        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", ['extracurricular_ulid' => $foreign->ulid])
            ->assertStatus(422);
    }

    public function test_a_parent_cannot_enroll_into_inactive_or_old_year_activities(): void
    {
        $inactive = $this->ekskul(['name' => 'Ditiadakan', 'is_active' => false]);

        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", ['extracurricular_ulid' => $inactive->ulid])
            ->assertStatus(422);

        $oldYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $stale = $this->ekskul(['name' => 'Tahun Lalu', 'academic_year_id' => $oldYear->id]);

        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$this->student->ulid}/extracurriculars", ['extracurricular_ulid' => $stale->ulid])
            ->assertStatus(422);
    }

    public function test_a_parent_cannot_enroll_another_familys_child(): void
    {
        $stranger = Student::create(['nama_lengkap' => 'Anak Keluarga Lain', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        $ekskul = $this->ekskul();

        $this->actingAs($this->parent)
            ->postJson("/api/wali/students/{$stranger->ulid}/extracurriculars", ['extracurricular_ulid' => $ekskul->ulid])
            ->assertStatus(404);

        $this->assertSame(0, ExtracurricularMember::count());
    }
}
