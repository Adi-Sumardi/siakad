<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-only audit trail viewer. Two things must hold: the trail answers
 * "who did what, when" with subjects as ULIDs (numeric keys never leave the
 * API), and nobody but a central admin can read it - a leak here is a leak
 * about other people's children and money.
 */
class ActivityLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role).uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function rule(): PointRule
    {
        return PointRule::create([
            'code' => 'TL-01', 'name' => 'Terlambat masuk kelas', 'type' => 'violation',
            'category' => 'Kedisiplinan', 'points' => 5,
        ]);
    }

    public function test_central_admin_sees_the_trail_with_ulids_not_numeric_ids(): void
    {
        $admin = $this->staff('admin');
        $rule = $this->rule();

        ActivityLog::record($admin, 'point_rule.created', $rule, ['code' => $rule->code]);

        $row = $this->actingAs($admin)->getJson('/api/admin/activity-logs')
            ->assertOk()
            ->json('logs.data.0');

        $this->assertSame('point_rule.created', $row['action']);
        $this->assertSame($admin->name, $row['user']['name']);
        $this->assertSame('PointRule', $row['subject_type']);
        $this->assertSame($rule->ulid, $row['subject_ulid']);
        $this->assertArrayNotHasKey('subject_id', $row);
        $this->assertSame(['code' => $rule->code], $row['meta']);
    }

    public function test_a_unit_admin_cannot_reach_the_trail(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $unitAdmin = $this->staff('admin_unit', $sd);

        $this->actingAs($unitAdmin)->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    public function test_filters_narrow_by_action_and_actor(): void
    {
        $admin = $this->staff('admin');
        $guru = $this->staff('guru');

        ActivityLog::record($guru, 'point.recorded');
        ActivityLog::record($admin, 'bill.waived');

        $this->actingAs($admin)->getJson('/api/admin/activity-logs?action=point.')
            ->assertOk()
            ->assertJsonPath('logs.data.0.action', 'point.recorded')
            ->assertJsonPath('logs.meta.total', 1);

        $this->actingAs($admin)->getJson('/api/admin/activity-logs?user=Guru')
            ->assertOk()
            ->assertJsonPath('logs.data.0.action', 'point.recorded')
            ->assertJsonPath('logs.meta.total', 1);

        $this->actingAs($admin)->getJson('/api/admin/activity-logs?action=tidak-ada')
            ->assertOk()
            ->assertJsonPath('logs.meta.total', 0);
    }

    public function test_a_deleted_subject_resolves_to_null_but_the_row_stays(): void
    {
        $admin = $this->staff('admin');
        $rule = $this->rule();

        ActivityLog::record($admin, 'point_rule.deleted', $rule, ['code' => $rule->code]);
        $rule->delete();

        $row = $this->actingAs($admin)->getJson('/api/admin/activity-logs')
            ->assertOk()
            ->json('logs.data.0');

        $this->assertSame('point_rule.deleted', $row['action']);
        $this->assertNull($row['subject_ulid']);
    }
}
