<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $standard = RoomType::where('name', 'Standard Room')->first();
        $deluxe = RoomType::where('name', 'Deluxe Room')->first();
        $suite = RoomType::where('name', 'Family Suite')->first();

        $rooms = [
            ['room_type_id' => $standard->id, 'room_number' => '101', 'floor' => 1],
            ['room_type_id' => $standard->id, 'room_number' => '102', 'floor' => 1],
            ['room_type_id' => $standard->id, 'room_number' => '103', 'floor' => 1],
            ['room_type_id' => $deluxe->id, 'room_number' => '201', 'floor' => 2],
            ['room_type_id' => $deluxe->id, 'room_number' => '202', 'floor' => 2],
            ['room_type_id' => $suite->id, 'room_number' => '301', 'floor' => 3],
            ['room_type_id' => $suite->id, 'room_number' => '302', 'floor' => 3],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(['room_number' => $room['room_number']], $room);
        }
    }
}
