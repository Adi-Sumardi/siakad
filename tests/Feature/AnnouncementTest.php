<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scope reads narrowest-first: a classroom notice reaches only that room, a
 * unit notice reaches its whole unit, and a school-wide one reaches everyone -
 * mirroring PMB's own Announcement, extended one level down to the classroom.
 */
class AnnouncementTest extends TestCase
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

    private function studentInClassroom(SchoolUnit $unit, string $className): Student
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa '.$className, 'jenis_kelamin' => 'P',
            'school_unit_id' => $unit->id, 'status' => 'active',
        ]);

        $classroom = Classroom::firstOrCreate(
            ['school_unit_id' => $unit->id, 'academic_year_id' => $this->year->id, 'name' => $className],
            ['tingkat' => 1],
        );

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->year->id, 'joined_on' => now(),
        ]);

        return $student->fresh();
    }

    private function guardianFor(Student ...$students): User
    {
        $user = User::create([
            'name' => 'Budi', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Budi', 'hubungan' => 'ayah', 'email' => $user->email]);

        foreach ($students as $student) {
            $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);
        }

        return $user;
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Pengumuman', 'body' => 'Isi pengumuman', 'published_at' => now()->subHour(),
        ], $overrides));
    }

    public function test_a_school_wide_notice_reaches_every_guardian(): void
    {
        $sd = $this->studentInClassroom($this->sd, '1A');
        $smp = $this->studentInClassroom($this->smp, '7A');
        $this->announcement();

        $this->actingAs($this->guardianFor($sd))->getJson('/api/wali/announcements')->assertJsonCount(1, 'announcements');
        $this->actingAs($this->guardianFor($smp))->getJson('/api/wali/announcements')->assertJsonCount(1, 'announcements');
    }

    public function test_a_unit_notice_reaches_only_that_units_families(): void
    {
        $sdStudent = $this->studentInClassroom($this->sd, '1A');
        $smpStudent = $this->studentInClassroom($this->smp, '7A');
        $this->announcement(['school_unit_id' => $this->sd->id]);

        $this->actingAs($this->guardianFor($sdStudent))->getJson('/api/wali/announcements')->assertJsonCount(1, 'announcements');
        $this->actingAs($this->guardianFor($smpStudent))->getJson('/api/wali/announcements')->assertJsonCount(0, 'announcements');
    }

    public function test_a_classroom_notice_reaches_only_that_classroom(): void
    {
        $a = $this->studentInClassroom($this->sd, '1A');
        $b = $this->studentInClassroom($this->sd, '1B');
        $classroomA = Classroom::where('name', '1A')->first();

        $this->announcement(['school_unit_id' => $this->sd->id, 'classroom_id' => $classroomA->id]);

        $this->actingAs($this->guardianFor($a))->getJson('/api/wali/announcements')->assertJsonCount(1, 'announcements');
        $this->actingAs($this->guardianFor($b))->getJson('/api/wali/announcements')->assertJsonCount(0, 'announcements');
    }

    public function test_a_guardian_with_children_in_two_units_sees_both_units_notices(): void
    {
        $sdChild = $this->studentInClassroom($this->sd, '1A');
        $smpChild = $this->studentInClassroom($this->smp, '7A');
        $user = $this->guardianFor($sdChild, $smpChild);

        $this->announcement(['school_unit_id' => $this->sd->id, 'title' => 'Untuk SD']);
        $this->announcement(['school_unit_id' => $this->smp->id, 'title' => 'Untuk SMP']);
        $otherUnit = SchoolUnit::create(['code' => 'TK-SAKINAH', 'label' => 'TK Sakinah', 'jenjang_group' => 'tk']);
        $this->announcement(['school_unit_id' => $otherUnit->id, 'title' => 'Bukan urusan keluarga ini']);

        // Both of this family's units, and nothing from a third unit neither
        // child attends.
        $titles = $this->actingAs($user)->getJson('/api/wali/announcements')->json('announcements');
        $this->assertCount(2, $titles);
        $this->assertNotContains('Bukan urusan keluarga ini', collect($titles)->pluck('title'));
    }

    public function test_a_future_published_at_is_not_live_yet(): void
    {
        $student = $this->studentInClassroom($this->sd, '1A');
        $this->announcement(['published_at' => now()->addDay()]);

        $this->actingAs($this->guardianFor($student))->getJson('/api/wali/announcements')->assertJsonCount(0, 'announcements');
    }

    public function test_a_unit_admin_cannot_post_a_school_wide_announcement(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->postJson('/api/admin/announcements', [
            'title' => 'Coba sekolah-wide', 'body' => 'X',
        ])->assertStatus(201);

        // Forced to their own unit regardless of what was (not) asked for.
        $this->assertSame($this->sd->id, Announcement::first()->school_unit_id);
    }

    public function test_a_central_admin_can_post_school_wide(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->postJson('/api/admin/announcements', [
            'title' => 'Libur nasional', 'body' => 'X',
        ])->assertStatus(201);

        $this->assertNull(Announcement::first()->school_unit_id);
    }

    public function test_a_unit_admin_cannot_edit_another_units_announcement(): void
    {
        $announcement = $this->announcement(['school_unit_id' => $this->smp->id]);
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->patchJson("/api/admin/announcements/{$announcement->ulid}", ['title' => 'Diretas'])
            ->assertStatus(404);
    }

    public function test_a_unit_admin_sees_the_school_wide_notices_alongside_their_own(): void
    {
        $this->announcement(['title' => 'Sekolah']);
        $this->announcement(['title' => 'SD saja', 'school_unit_id' => $this->sd->id]);
        $this->announcement(['title' => 'SMP saja', 'school_unit_id' => $this->smp->id]);

        $admin = $this->staff('admin_unit', $this->sd);

        $titles = $this->actingAs($admin)->getJson('/api/admin/announcements')->json('announcements');
        $this->assertCount(2, $titles);
    }

    public function test_a_unit_admin_cannot_edit_a_school_wide_announcement(): void
    {
        $announcement = $this->announcement(); // school-wide
        $admin = $this->staff('admin_unit', $this->sd);

        // Visible to them (proven above), but not theirs to change - a
        // per-unit admin reading their families' school-wide notice must not
        // also be able to silently rewrite it.
        $this->actingAs($admin)->patchJson("/api/admin/announcements/{$announcement->ulid}", ['title' => 'Diretas'])
            ->assertStatus(404);
    }

    public function test_a_guardian_cannot_manage_announcements_at_all(): void
    {
        $student = $this->studentInClassroom($this->sd, '1A');
        $user = $this->guardianFor($student);

        $this->actingAs($user)->postJson('/api/admin/announcements', ['title' => 'X', 'body' => 'X'])
            ->assertStatus(403);
    }
}
