<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingOverlapTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRoom(): Room
    {
        $roomType = RoomType::create([
            'name' => 'Standard Room',
            'description' => 'Test room type',
            'base_price' => 2500,
            'capacity' => 2,
            'amenities' => ['Wi-Fi'],
        ]);

        return Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'floor' => 1,
            'status' => 'available',
        ]);
    }

    protected function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_second_booking_on_overlapping_dates_is_rejected(): void
    {
        $room = $this->makeRoom();
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->tokenFor($user);

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 2,
        ]);
        $first->assertCreated();

        // Overlaps the middle of the first booking's range.
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(9)->toDateString(),
            'guests' => 2,
        ]);

        $second->assertStatus(422);
        $second->assertJsonPath('errors.check_in.0', 'This room is already booked for part of the selected date range.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_back_to_back_bookings_on_adjacent_dates_are_allowed(): void
    {
        $room = $this->makeRoom();
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->tokenFor($user);

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 2,
        ]);
        $first->assertCreated();

        // Check-in the day the first booking checks out — not an overlap.
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(8)->toDateString(),
            'check_out' => now()->addDays(10)->toDateString(),
            'guests' => 2,
        ]);

        $second->assertCreated();
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_cancelled_booking_frees_the_room_for_the_same_dates(): void
    {
        $room = $this->makeRoom();
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->tokenFor($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $first = $this->withHeaders($headers)->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 2,
        ])->assertCreated();

        $bookingId = $first->json('id') ?? $first->json('data.id');

        $this->withHeaders($headers)->deleteJson("/api/bookings/{$bookingId}")->assertOk();

        $second = $this->withHeaders($headers)->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 2,
        ]);

        $second->assertCreated();
    }

    public function test_room_search_excludes_rooms_booked_for_the_requested_range(): void
    {
        $room = $this->makeRoom();
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 2,
        ])->assertCreated();

        $available = $this->getJson('/api/rooms?check_in=' . now()->addDays(6)->toDateString() . '&check_out=' . now()->addDays(7)->toDateString());
        $available->assertOk();
        $ids = collect($available->json('data') ?? $available->json())->pluck('id')->all();
        $this->assertNotContains($room->id, $ids);

        $stillAvailable = $this->getJson('/api/rooms?check_in=' . now()->addDays(20)->toDateString() . '&check_out=' . now()->addDays(21)->toDateString());
        $ids2 = collect($stillAvailable->json('data') ?? $stillAvailable->json())->pluck('id')->all();
        $this->assertContains($room->id, $ids2);
    }

    public function test_guests_exceeding_room_type_capacity_is_rejected(): void
    {
        $room = $this->makeRoom(); // capacity 2
        $user = User::factory()->create(['role' => 'customer']);
        $token = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('guests');
    }
}
