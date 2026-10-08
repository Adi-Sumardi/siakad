<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Log Aktivitas (2026-10-01): every admin row carries who did it - name,
 * role, unit as they were - in words, with whether it went through; actions
 * without an explicit record (downloads, refused attempts) are caught by
 * LogAdminActivity. Only the central admin reads it.
 */
class ActivityLogAdminTrailTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private User $central;

    private User $tuSd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->central = $this->staff('admin');
        $this->tuSd = $this->staff('admin_unit', $this->sd);
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => 'Test '.ucfirst($role).' '.uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_a_unit_admins_change_is_logged_with_their_name_unit_and_outcome(): void
    {
        $subject = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK', 'name' => 'Matematika']);

        $this->actingAs($this->tuSd)
            ->patchJson("/api/admin/subjects/{$subject->ulid}", ['name' => 'Matematika Dasar'])
            ->assertOk();

        $log = ActivityLog::where('action', 'subject.updated')->sole();
        $this->assertSame($this->tuSd->name, $log->user_name);
        $this->assertSame('admin_unit', $log->role);
        $this->assertSame('SD Islam Al Azhar 13', $log->unit_label);
        $this->assertSame('Mengubah mata pelajaran', $log->label);
        $this->assertSame('Akademik', $log->category);
        $this->assertSame(200, $log->status);
        // The explicit record covered it - no second, generic row.
        $this->assertSame(1, ActivityLog::count());
    }

    public function test_downloads_and_refused_attempts_are_caught_by_the_safety_net(): void
    {
        $this->actingAs($this->central)->get('/api/admin/import/users/template')->assertOk();
        // School units are central-admin only.
        $this->actingAs($this->tuSd)->postJson('/api/admin/school-units', ['code' => 'X', 'label' => 'X'])->assertForbidden();
        // A plain read is never logged.
        $this->actingAs($this->central)->getJson('/api/admin/subjects')->assertOk();

        $logs = ActivityLog::orderBy('id')->get();
        $this->assertSame(['Mengunduh template import user', 'Menambah unit sekolah'], $logs->pluck('label')->all());
        $this->assertSame('Unduhan', $logs[0]->category);
        $this->assertSame(403, $logs[1]->status);
        $this->assertSame('SD Islam Al Azhar 13', $logs[1]->unit_label);
        // Field names, never values.
        $this->assertSame(['code', 'label'], $logs[1]->meta['fields']);
    }

    public function test_only_the_central_admin_reads_it_filtered_by_unit_role_and_outcome(): void
    {
        $this->actingAs($this->central)->get('/api/admin/import/users/template')->assertOk();
        $this->actingAs($this->tuSd)->postJson('/api/admin/school-units', ['code' => 'X', 'label' => 'X'])->assertForbidden();

        $this->actingAs($this->tuSd)->getJson('/api/admin/activity-logs')->assertForbidden();

        $this->actingAs($this->central)->getJson("/api/admin/activity-logs?unit={$this->sd->id}")
            ->assertOk()
            // Just the refused attempt - opening the log (a read) is never logged.
            ->assertJsonPath('logs.meta.total', 1)
            ->assertJsonPath('logs.data.0.unit', 'SD Islam Al Azhar 13');

        $this->actingAs($this->central)->getJson('/api/admin/activity-logs?unit=pusat&role=admin')
            ->assertJsonPath('logs.data.0.label', 'Mengunduh template import user')
            ->assertJsonPath('logs.data.0.success', true);

        $this->actingAs($this->central)->getJson('/api/admin/activity-logs?status=failed&role=admin_unit')
            ->assertJsonPath('logs.meta.total', 1)
            ->assertJsonPath('logs.data.0.success', false);
    }
}
