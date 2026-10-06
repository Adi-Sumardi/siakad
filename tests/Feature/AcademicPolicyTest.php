<?php

namespace Tests\Feature;

use App\Models\AcademicPolicy;
use App\Models\SchoolUnit;
use App\Models\User;
use App\Services\Academic\GradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Audit 6 Okt 2026 #11-12: grade weights and watchlist thresholds as policy. */
class AcademicPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => 'U', 'email' => $role.uniqid().'@yapinet.id', 'role' => $role,
            'school_unit_id' => $unit?->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_defaults_school_wide_and_unit_override_resolve_in_order(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-1', 'label' => 'SD Satu', 'jenjang_group' => 'sd']);
        $smp = SchoolUnit::create(['code' => 'SMP-1', 'label' => 'SMP Satu', 'jenjang_group' => 'smp']);

        // Empty table = the old constants, so nothing changes until saved.
        $this->assertSame(70, AcademicPolicy::forUnit($sd->id)->kkm);
        $this->assertSame(GradeService::WEIGHTS, AcademicPolicy::forUnit($sd->id)->weights());

        $admin = $this->user('admin');

        $this->actingAs($admin)->putJson('/api/admin/academic-policy', [
            'weight_tugas' => 30, 'weight_uts' => 30, 'weight_uas' => 30,
            'kkm' => 75, 'alpa_threshold' => 3, 'grade_drop' => 10,
        ])->assertStatus(422); // not 100%

        $this->actingAs($admin)->putJson('/api/admin/academic-policy', [
            'weight_tugas' => 30, 'weight_uts' => 30, 'weight_uas' => 40,
            'kkm' => 75, 'alpa_threshold' => 3, 'grade_drop' => 10,
        ])->assertOk();

        $this->actingAs($admin)->putJson('/api/admin/academic-policy', [
            'unit' => $smp->ulid,
            'weight_tugas' => 20, 'weight_uts' => 30, 'weight_uas' => 50,
            'kkm' => 78, 'alpa_threshold' => 5, 'grade_drop' => 5,
        ])->assertOk();

        $this->assertSame(75, AcademicPolicy::forUnit($sd->id)->kkm);
        $this->assertSame(78, AcademicPolicy::forUnit($smp->id)->kkm);
        $this->assertEquals(
            86.2, // 80*.3 + 90*.3 + 88*.4
            GradeService::weighted(collect(['tugas' => 80, 'uts' => 90, 'uas' => 88]), AcademicPolicy::forUnit($sd->id)->weights()),
        );

        // A unit admin reads but cannot write.
        $tu = $this->user('admin_unit', $sd);
        $this->actingAs($tu)->getJson('/api/admin/academic-policy')
            ->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('units.0.kkm', 75);
        $this->actingAs($tu)->putJson('/api/admin/academic-policy', [
            'weight_tugas' => 20, 'weight_uts' => 30, 'weight_uas' => 50, 'kkm' => 10, 'alpa_threshold' => 5, 'grade_drop' => 5,
        ])->assertForbidden();

        $this->actingAs($admin)->deleteJson("/api/admin/academic-policy/{$smp->ulid}")->assertOk();
        $this->assertSame(75, AcademicPolicy::forUnit($smp->id)->kkm);
    }
}
