<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wali welcome splash (2026-09-30) shows once per account: welcomed_at
 * rides on /api/auth/me, and POST /api/auth/welcomed sets it the first time.
 */
class WelcomeSplashTest extends TestCase
{
    use RefreshDatabase;

    private function wali(): User
    {
        return User::create([
            'name' => 'Test Wali', 'email' => 'wali'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_a_new_account_has_not_been_welcomed_yet(): void
    {
        $this->actingAs($this->wali())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.welcomed_at', null);
    }

    public function test_marking_it_seen_sticks_and_keeps_the_first_time(): void
    {
        $wali = $this->wali();

        $first = $this->actingAs($wali)->postJson('/api/auth/welcomed')->assertOk()->json('user.welcomed_at');
        $this->assertNotNull($first);

        $this->travel(2)->days();
        $this->actingAs($wali)->postJson('/api/auth/welcomed')->assertOk()->assertJsonPath('user.welcomed_at', $first);
        $this->actingAs($wali)->getJson('/api/auth/me')->assertJsonPath('user.welcomed_at', $first);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/auth/welcomed')->assertUnauthorized();
    }
}
