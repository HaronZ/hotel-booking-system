<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\BookingStatusUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CompletePastBookingsCommandTest extends TestCase
{
    use RefreshDatabase;

    // Booking creation validates check_in >= today, so a past stay (the
    // whole point of this command) can only be set up by writing the model
    // directly rather than going through the booking-creation endpoint.
    protected function makeBooking(string $status, string $checkIn, string $checkOut): Booking
    {
        $roomType = RoomType::create(['name' => 'Standard', 'base_price' => 1000, 'capacity' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        $customer = User::factory()->create(['role' => 'customer']);

        return Booking::create([
            'user_id' => $customer->id,
            'room_id' => $room->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => 1,
            'status' => $status,
            'status_changed_by' => $customer->id,
            'total_price' => 1000,
        ]);
    }

    public function test_it_completes_confirmed_bookings_whose_check_out_has_passed(): void
    {
        Notification::fake();
        $booking = $this->makeBooking('confirmed', now()->subDays(5)->toDateString(), now()->subDays(2)->toDateString());

        $this->artisan('bookings:complete-past')->assertExitCode(0);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'completed']);
        Notification::assertSentTo(
            $booking->user,
            BookingStatusUpdatedNotification::class,
            fn ($notification) => $notification->booking->status === 'completed'
        );
    }

    public function test_it_leaves_confirmed_bookings_whose_check_out_has_not_passed(): void
    {
        $booking = $this->makeBooking('confirmed', now()->addDays(2)->toDateString(), now()->addDays(5)->toDateString());

        $this->artisan('bookings:complete-past');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    }

    public function test_it_leaves_pending_bookings_alone_even_if_the_check_out_date_passed(): void
    {
        $booking = $this->makeBooking('pending', now()->subDays(5)->toDateString(), now()->subDays(2)->toDateString());

        $this->artisan('bookings:complete-past');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
    }
}
