<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Guest',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['user', 'access_token', 'token_type', 'expires_in']);
        $this->assertSame('customer', $response->json('user.role'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_succeeds_and_me_returns_the_user(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'correct-password',
        ])->assertOk();

        $token = $login->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', 'jane@example.com');
    }

    public function test_a_customer_cannot_create_a_room_type(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $token = auth('api')->login($customer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/room-types', [
            'name' => 'Penthouse',
            'base_price' => 9999,
            'capacity' => 4,
        ]);

        $response->assertStatus(403);
    }

    public function test_an_admin_can_create_a_room_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = auth('api')->login($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/room-types', [
            'name' => 'Penthouse',
            'base_price' => 9999,
            'capacity' => 4,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('room_types', ['name' => 'Penthouse']);
    }

    public function test_guest_routes_reject_missing_token(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
        $this->getJson('/api/bookings')->assertStatus(401);
    }
}
