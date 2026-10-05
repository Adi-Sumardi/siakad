<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The welcome splash (2026-09-30) shows once per account: welcomed_at rides
 * on /api/auth/me, and POST /api/auth/welcomed sets it the first time. The
 * endpoint serves every role; the splash itself greets wali, guru, and admin
 * unit today.
 */
class WelcomeSplashTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Test '.ucfirst($role), 'email' => $role.uniqid().'@example.com',
            'role' => $role, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public static function roles(): array
    {
        return [['orangtua'], ['guru'], ['admin_unit'], ['admin']];
    }

    public function test_a_new_account_has_not_been_welcomed_yet(): void
    {
        $this->actingAs($this->makeUser('orangtua'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.welcomed_at', null);
    }

    #[DataProvider('roles')]
    public function test_every_role_can_mark_the_splash_seen(string $role): void
    {
        $this->actingAs($this->makeUser($role))
            ->postJson('/api/auth/welcomed')
            ->assertOk()
            ->assertJsonPath('user.welcomed_at', fn (?string $at) => $at !== null);
    }

    public function test_marking_it_seen_sticks_and_keeps_the_first_time(): void
    {
        $wali = $this->makeUser('orangtua');

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
