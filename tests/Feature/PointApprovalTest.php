<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Points\PointLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The approval ladder (mentor model, 15 Okt 2026):
 *   pelanggaran: guru -> wali kelas -> waka kesiswaan -> kepala sekolah (berat)
 *   apresiasi:   guru -> wali kelas
 * Nothing counts, and no parent sees it, until the last stage approves.
 */
class PointApprovalTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;
    private Term $term;
    private Classroom $room;
    private Student $student;
    private User $guru;
    private User $walas;
    private User $waka;
    private User $kepsek;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SMP-T', 'label' => 'SMP Test', 'jenjang_group' => 'smp']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true]);

        $this->guru = $this->user('guru');
        $this->walas = $this->user('guru');
        $this->waka = $this->user('waka_kesiswaan');
        $this->kepsek = $this->user('kepala_sekolah');

        $this->room = Classroom::create(['school_unit_id' => $this->unit->id, 'academic_year_id' => $year->id, 'name' => '7-A', 'tingkat' => 7, 'homeroom_teacher_id' => $this->walas->id]);
        $this->student = Student::create(['nama_lengkap' => 'Faris', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id, 'status' => 'active', 'joined_on' => '2026-07-01']);
    }

    private function user(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id', 'role' => $role,
            'school_unit_id' => ($unit ?? $this->unit)->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function rule(string $type = 'violation', string $severity = 'ringan', int $points = 10): PointRule
    {
        return PointRule::create([
            'code' => 'R-'.uniqid(), 'name' => 'Aturan '.$severity, 'type' => $type,
            'category' => 'Kedisiplinan', 'severity' => $severity, 'points' => $points,
        ]);
    }

    private function recordVia(User $by, PointRule $rule): PointRecord
    {
        $this->actingAs($by)->postJson('/api/guru/points', [
            'student_ulid' => $this->student->ulid, 'point_rule_ulid' => $rule->ulid,
            'occurred_on' => now()->toDateString(), 'description' => 'Kejadian di kelas',
        ])->assertCreated();

        return PointRecord::latest('id')->first();
    }

    private function balance(): int
    {
        return app(PointLedger::class)->balance($this->student, $this->term);
    }

    public function test_a_violation_climbs_wali_kelas_then_waka_before_it_counts(): void
    {
        $record = $this->recordVia($this->guru, $this->rule());

        $this->assertSame('pending', $record->status);
        $this->assertSame('wali_kelas', $record->approval_stage);
        $this->assertSame(0, $this->balance());

        // The waka cannot jump the queue.
        $this->actingAs($this->waka)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")->assertStatus(422);

        $this->actingAs($this->walas)->getJson('/api/guru/point-approvals')->assertOk()->assertJsonCount(1, 'pending');
        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.approval_stage', 'waka_kesiswaan');
        $this->assertSame(0, $this->balance());

        $this->actingAs($this->waka)->getJson('/api/admin/point-approvals')->assertOk()->assertJsonCount(1, 'pending');
        $this->actingAs($this->waka)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.status', 'recorded');

        $this->assertSame(-10, $this->balance());
        // The kepala sekolah was never asked: ringan stops at the waka.
        $this->actingAs($this->kepsek)->getJson('/api/admin/point-approvals')->assertJsonCount(0, 'pending');
        $this->assertSame(['approved', 'approved'], $record->fresh()->approvals->pluck('decision')->all());
    }

    public function test_a_berat_violation_also_needs_the_kepala_sekolah(): void
    {
        $record = $this->recordVia($this->guru, $this->rule('violation', 'berat', 50));

        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertOk();
        $this->actingAs($this->waka)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.approval_stage', 'kepala_sekolah');
        $this->assertSame(0, $this->balance());

        $this->actingAs($this->kepsek)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.status', 'recorded');
        $this->assertSame(-50, $this->balance());
    }

    public function test_a_merit_needs_only_the_wali_kelas(): void
    {
        $record = $this->recordVia($this->guru, $this->rule('merit', 'ringan', 5));

        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.status', 'recorded');
        $this->assertSame(5, $this->balance());
    }

    public function test_the_wali_kelas_recording_for_their_own_class_skips_their_own_stage(): void
    {
        $record = $this->recordVia($this->walas, $this->rule());

        $this->assertSame('waka_kesiswaan', $record->approval_stage);
        $this->assertSame('skipped', $record->approvals->first()->decision);

        // A merit by the wali kelas therefore counts straight away.
        $merit = $this->recordVia($this->walas, $this->rule('merit', 'ringan', 5));
        $this->assertSame('recorded', $merit->status);
    }

    public function test_a_rejection_ends_the_record_and_it_never_counts(): void
    {
        $record = $this->recordVia($this->guru, $this->rule());
        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertOk();

        $this->actingAs($this->waka)->postJson("/api/admin/point-approvals/{$record->ulid}/reject", ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->waka)->postJson("/api/admin/point-approvals/{$record->ulid}/reject", ['reason' => 'Bukan pelanggaran, siswa izin piket OSIS'])
            ->assertOk()->assertJsonPath('record.status', 'rejected');

        $this->assertSame(0, $this->balance());
        $this->actingAs($this->kepsek)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")->assertStatus(422);
    }

    public function test_only_the_seat_holder_decides(): void
    {
        $record = $this->recordVia($this->guru, $this->rule());

        // Another teacher, the recorder, and another unit's waka are all refused.
        $this->actingAs($this->guru)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertStatus(422);
        $this->actingAs($this->user('guru'))->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertStatus(422);

        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertOk();

        $otherUnit = SchoolUnit::create(['code' => 'SMP-X', 'label' => 'SMP X', 'jenjang_group' => 'smp']);
        $this->actingAs($this->user('waka_kesiswaan', $otherUnit))->postJson("/api/admin/point-approvals/{$record->ulid}/approve")->assertStatus(404);
        $this->assertSame('waka_kesiswaan', $record->fresh()->approval_stage);
    }

    public function test_admin_unit_sits_in_for_a_vacant_seat_only(): void
    {
        $admin = $this->user('admin_unit');
        $record = $this->recordVia($this->guru, $this->rule());
        $this->actingAs($this->walas)->postJson("/api/guru/point-approvals/{$record->ulid}/approve")->assertOk();

        // The unit has a waka - the admin may not decide for them.
        $this->actingAs($admin)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")->assertStatus(422);

        $this->waka->update(['is_active' => false]);
        $this->actingAs($admin)->getJson('/api/admin/point-approvals')->assertJsonCount(1, 'pending');
        $this->actingAs($admin)->postJson("/api/admin/point-approvals/{$record->ulid}/approve")
            ->assertOk()->assertJsonPath('record.status', 'recorded');
    }

    public function test_a_class_without_wali_kelas_goes_straight_to_the_waka(): void
    {
        $this->room->update(['homeroom_teacher_id' => null]);

        $record = $this->recordVia($this->guru, $this->rule());

        $this->assertSame('waka_kesiswaan', $record->approval_stage);
    }

    public function test_parents_see_only_decided_records(): void
    {
        $parent = User::create(['name' => 'Ortu', 'email' => 'ortu'.uniqid().'@x.id', 'role' => 'orangtua', 'is_active' => true, 'activated_at' => now()]);
        $guardian = Guardian::create(['user_id' => $parent->id, 'nama' => 'Ortu', 'hubungan' => 'ayah']);
        $this->student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $this->recordVia($this->guru, $this->rule());

        $this->actingAs($parent)->getJson("/api/wali/students/{$this->student->ulid}/points")
            ->assertOk()->assertJsonPath('balance', 0)->assertJsonCount(0, 'records');
    }

    public function test_bulk_approval_and_the_badge_count(): void
    {
        $rule = $this->rule();
        $a = $this->recordVia($this->guru, $rule);
        $b = $this->recordVia($this->guru, $rule);

        $this->actingAs($this->walas)->getJson('/api/guru/point-approvals/count')->assertJsonPath('pending', 2);
        $this->actingAs($this->walas)->postJson('/api/guru/point-approvals/approve-many', ['ulids' => [$a->ulid, $b->ulid]])
            ->assertOk()->assertJsonPath('approved', 2);
        $this->actingAs($this->waka)->getJson('/api/admin/point-approvals/count')->assertJsonPath('pending', 2);
    }

    public function test_only_the_recorder_or_wali_kelas_may_revoke_and_a_pending_record_can_be_withdrawn(): void
    {
        $record = $this->recordVia($this->guru, $this->rule());

        $this->actingAs($this->user('guru'))->patchJson("/api/guru/points/{$record->ulid}/revoke", ['reason' => 'Bukan saya'])->assertForbidden();
        $this->actingAs($this->guru)->patchJson("/api/guru/points/{$record->ulid}/revoke", ['reason' => 'Salah siswa'])
            ->assertOk()->assertJsonPath('record.status', 'revoked');

        $this->actingAs($this->walas)->getJson('/api/guru/point-approvals/count')->assertJsonPath('pending', 0);
    }

    public function test_leaders_get_the_kesiswaan_slice_of_the_admin_area_only(): void
    {
        $this->actingAs($this->kepsek)->getJson('/api/admin/points')->assertOk();
        $this->actingAs($this->kepsek)->getJson('/api/admin/point-rules')->assertOk();
        $this->actingAs($this->kepsek)->getJson('/api/admin/dashboard/summary')->assertForbidden();
        $this->actingAs($this->waka)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($this->waka)->postJson('/api/admin/point-rules', [])->assertForbidden();
        $this->actingAs($this->waka)->getJson('/api/guru/classrooms')->assertForbidden();
    }

    public function test_admin_unit_can_create_a_waka_for_its_own_unit(): void
    {
        $admin = $this->user('admin_unit');

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Bu Waka', 'email' => 'waka.baru@yapinet.id', 'role' => 'waka_kesiswaan',
        ])->assertCreated();

        $this->assertSame($this->unit->id, User::where('email', 'waka.baru@yapinet.id')->value('school_unit_id'));
    }
}
