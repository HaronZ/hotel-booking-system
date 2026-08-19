<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{booking: array, customer: User, admin: User}
     */
    protected function makeBooking(string $status = 'pending'): array
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);

        $booking = $this->actingAs($customer, 'api')->postJson('/api/bookings', [
            'room_id' => $room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(),
            'guests' => 1,
        ])->json();

        if ($status !== 'pending') {
            $this->actingAs($admin, 'api')->putJson("/api/bookings/{$booking['id']}", ['status' => $status]);
        }

        return compact('booking', 'customer', 'admin');
    }

    public function test_creating_a_booking_records_the_customer_as_who_set_the_status(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBooking();

        $this->assertSame($customer->id, $booking['status_changed_by']);
    }

    public function test_admin_changing_status_records_the_admin(): void
    {
        ['booking' => $booking, 'admin' => $admin] = $this->makeBooking();

        $response = $this->actingAs($admin, 'api')->putJson("/api/bookings/{$booking['id']}", ['status' => 'confirmed']);

        // The eager-loaded statusChangedBy relation serializes under the
        // same status_changed_by key as the raw column, so it comes back
        // as {id, name, role} rather than a bare id - that's intentional,
        // it's exactly the "who" info the admin table needs to display.
        $response->assertOk();
        $response->assertJsonPath('status_changed_by.id', $admin->id);
        $response->assertJsonPath('status_changed_by.role', 'admin');
    }

    public function test_customer_cancelling_their_own_booking_records_the_customer(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBooking();

        $this->actingAs($customer, 'api')->deleteJson("/api/bookings/{$booking['id']}")->assertOk();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking['id'],
            'status' => 'cancelled',
            'status_changed_by' => $customer->id,
        ]);
    }

    public function test_a_completed_booking_cannot_be_updated(): void
    {
        ['booking' => $booking, 'admin' => $admin] = $this->makeBooking('completed');

        $response = $this->actingAs($admin, 'api')->putJson("/api/bookings/{$booking['id']}", ['status' => 'confirmed']);

        $response->assertStatus(422);
    }

    public function test_a_cancelled_booking_cannot_be_uncancelled(): void
    {
        ['booking' => $booking, 'admin' => $admin] = $this->makeBooking('cancelled');

        $response = $this->actingAs($admin, 'api')->putJson("/api/bookings/{$booking['id']}", ['status' => 'confirmed']);

        $response->assertStatus(422);
    }

    public function test_a_completed_booking_cannot_be_cancelled(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBooking('completed');

        $response = $this->actingAs($customer, 'api')->deleteJson("/api/bookings/{$booking['id']}");

        $response->assertStatus(422);
    }
}
