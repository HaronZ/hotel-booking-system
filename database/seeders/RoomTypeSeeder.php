<?php

namespace Database\Seeders;

use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Standard Room',
                'description' => 'Cozy room with a queen bed, perfect for solo travelers or couples.',
                'base_price' => 2500.00,
                'capacity' => 2,
                'amenities' => ['Wi-Fi', 'Air Conditioning', 'TV'],
            ],
            [
                'name' => 'Deluxe Room',
                'description' => 'Spacious room with a king bed and city view.',
                'base_price' => 4200.00,
                'capacity' => 3,
                'amenities' => ['Wi-Fi', 'Air Conditioning', 'TV', 'Mini Bar', 'City View'],
            ],
            [
                'name' => 'Family Suite',
                'description' => 'Two-bedroom suite with a living area, ideal for families or groups.',
                'base_price' => 6800.00,
                'capacity' => 5,
                'amenities' => ['Wi-Fi', 'Air Conditioning', 'TV', 'Mini Bar', 'Breakfast Included', 'Sofa Bed'],
            ],
        ];

        foreach ($types as $type) {
            RoomType::updateOrCreate(['name' => $type['name']], $type);
        }
    }
}
