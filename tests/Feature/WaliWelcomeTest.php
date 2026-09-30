<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The once-per-account welcome splash: users.welcome_shown_at is the single
 * server-owned flag the SPA gates its overlay on, and the acknowledge
 * endpoint only ever stamps it the first time - a double tap or a second
 * tab must not shift the timestamp. The lane stays wali-only: staff get
 * the same 403 as every other /api/wali route.
 */
class WaliWelcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));
    }

    private function wali(): User
    {
        return User::create([
            'name' => 'Budi', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_me_reports_an_unseen_welcome_for_a_new_guardian(): void
    {
        $this->actingAs($this->wali())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.welcome_shown_at', null);
    }

    public function test_acknowledge_stamps_the_flag(): void
    {
        $user = $this->wali();

        $this->actingAs($user)
            ->postJson('/api/wali/welcome/acknowledge')
            ->assertOk()
            ->assertJsonPath('welcome_shown_at', $user->fresh()->welcome_shown_at->toISOString());

        $this->assertNotNull($user->fresh()->welcome_shown_at);
    }

    public function test_acknowledge_is_idempotent(): void
    {
        $user = $this->wali();

        $this->actingAs($user)->postJson('/api/wali/welcome/acknowledge')->assertOk();
        $first = $user->fresh()->welcome_shown_at;

        $this->travel(5)->minutes();

        $this->actingAs($user)->postJson('/api/wali/welcome/acknowledge')->assertOk();

        $this->assertTrue($first->equalTo($user->fresh()->welcome_shown_at));
    }

    public function test_staff_cannot_reach_the_wali_lane(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@example.com',
            'role' => 'admin', 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/wali/welcome/acknowledge')
            ->assertForbidden();

        $this->assertNull($admin->fresh()->welcome_shown_at);
    }

    public function test_guests_are_refused(): void
    {
        $this->postJson('/api/wali/welcome/acknowledge')->assertUnauthorized();
    }
}
