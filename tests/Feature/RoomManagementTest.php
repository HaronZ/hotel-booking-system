<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function adminToken(): string
    {
        return auth('api')->login(User::factory()->create(['role' => 'admin']));
    }

    public function test_updating_a_room_without_changing_its_number_succeeds(): void
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => '301', 'status' => 'available']);
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->putJson("/api/rooms/{$room->id}", [
            'room_type_id' => $roomType->id,
            'room_number' => '301',
            'floor' => 3,
            'status' => 'maintenance',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'maintenance');
    }

    public function test_creating_a_room_with_a_duplicate_number_is_rejected(): void
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '301', 'status' => 'available']);
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/rooms', [
            'room_type_id' => $roomType->id,
            'room_number' => '301',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('room_number');
    }

    public function test_updating_a_room_to_another_rooms_number_is_still_rejected(): void
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '301', 'status' => 'available']);
        $roomToEdit = Room::create(['room_type_id' => $roomType->id, 'room_number' => '302', 'status' => 'available']);
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->putJson("/api/rooms/{$roomToEdit->id}", [
            'room_type_id' => $roomType->id,
            'room_number' => '301',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('room_number');
    }

    public function test_guest_room_listing_excludes_rooms_under_maintenance(): void
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '301', 'status' => 'maintenance']);

        // No auth header and no check_in/check_out - the default browse state.
        $response = $this->getJson('/api/rooms');

        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('room_number');
        $this->assertContains('101', $numbers);
        $this->assertNotContains('301', $numbers);
    }

    public function test_admin_room_listing_still_includes_rooms_under_maintenance(): void
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['room_type_id' => $roomType->id, 'room_number' => '301', 'status' => 'maintenance']);
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/rooms');

        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('room_number');
        $this->assertContains('101', $numbers);
        $this->assertContains('301', $numbers);
    }
}
