<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BookingSeeder extends Seeder
{
    /**
     * A guest can only ever create a 'pending' booking or cancel their own
     * (the API refuses to let non-admins set any other status), so the demo
     * data needs to seed the other states directly - otherwise "confirmed"
     * and "completed" would never show up without manually editing one via
     * the admin panel first.
     */
    public function run(): void
    {
        $guest = User::where('email', 'guest@hotel.test')->first();

        $bookings = [
            ['room_number' => '101', 'check_in' => Carbon::today()->addDays(5), 'check_out' => Carbon::today()->addDays(8), 'guests' => 1, 'status' => 'pending'],
            ['room_number' => '102', 'check_in' => Carbon::today()->addDays(12), 'check_out' => Carbon::today()->addDays(15), 'guests' => 2, 'status' => 'confirmed'],
            ['room_number' => '201', 'check_in' => Carbon::today()->subDays(10), 'check_out' => Carbon::today()->subDays(7), 'guests' => 2, 'status' => 'completed'],
            ['room_number' => '301', 'check_in' => Carbon::today()->addDays(20), 'check_out' => Carbon::today()->addDays(23), 'guests' => 4, 'status' => 'cancelled'],
        ];

        foreach ($bookings as $data) {
            $room = Room::with('roomType')->where('room_number', $data['room_number'])->first();
            $nights = $data['check_in']->diffInDays($data['check_out']);

            Booking::updateOrCreate(
                [
                    'user_id' => $guest->id,
                    'room_id' => $room->id,
                    'check_in' => $data['check_in']->toDateString(),
                ],
                [
                    'check_out' => $data['check_out']->toDateString(),
                    'guests' => $data['guests'],
                    'status' => $data['status'],
                    'total_price' => $nights * $room->roomType->base_price,
                ]
            );
        }
    }
}
