<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ruang kontrol's dead-letter shelf (/admin/monitoring): failed_jobs
 * rows, read-only. The API surfaces the job class (displayName) and an
 * exception excerpt - the payload body stays in the database where the
 * queue left it.
 */
class FailedJobListTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_admin_sees_failed_jobs_with_display_name_and_excerpt(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin', 'is_active' => true, 'activated_at' => now(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ProcessPmbHandoffEvent',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'id' => 'test-job',
                'data' => [],
            ]),
            'exception' => str_repeat('x', 500),
            'failed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $body = $this->actingAs($admin)->getJson('/api/admin/failed-jobs')
            ->assertOk()
            ->json('jobs');

        $this->assertSame(1, $body['meta']['total']);
        $row = $body['data'][0];
        $this->assertSame('App\\Jobs\\ProcessPmbHandoffEvent', $row['display_name']);
        $this->assertSame('default', $row['queue']);
        $this->assertSame(200, mb_strlen($row['exception']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $row['failed_at']);
        $this->assertArrayNotHasKey('payload', $row);
    }
}
