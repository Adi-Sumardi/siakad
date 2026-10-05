<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "Panduan Fitur" tour (2026-10-02) runs once per account: onboarded_at
 * rides on /api/auth/me, and POST /api/auth/onboarded sets it the first time
 * - finishing or skipping both count as seen. The endpoint serves every
 * role; the tour itself runs for wali, guru, and admin unit today.
 */
class OnboardingTourTest extends TestCase
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

    public function test_a_new_account_has_not_seen_the_tour_yet(): void
    {
        $this->actingAs($this->makeUser('orangtua'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.onboarded_at', null);
    }

    #[DataProvider('roles')]
    public function test_every_role_can_mark_the_tour_seen(string $role): void
    {
        $this->actingAs($this->makeUser($role))
            ->postJson('/api/auth/onboarded')
            ->assertOk()
            ->assertJsonPath('user.onboarded_at', fn (?string $at) => $at !== null);
    }

    public function test_marking_it_seen_sticks_and_keeps_the_first_time(): void
    {
        $wali = $this->makeUser('orangtua');

        $first = $this->actingAs($wali)->postJson('/api/auth/onboarded')->assertOk()->json('user.onboarded_at');
        $this->assertNotNull($first);

        $this->travel(2)->days();
        $this->actingAs($wali)->postJson('/api/auth/onboarded')->assertOk()->assertJsonPath('user.onboarded_at', $first);
        $this->actingAs($wali)->getJson('/api/auth/me')->assertJsonPath('user.onboarded_at', $first);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/auth/onboarded')->assertUnauthorized();
    }
}
