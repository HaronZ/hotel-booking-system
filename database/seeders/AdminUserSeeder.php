<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@hotel.test'],
            [
                'name' => 'Hotel Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'guest@hotel.test'],
            [
                'name' => 'Demo Guest',
                'password' => Hash::make('password'),
                'role' => 'customer',
            ]
        );
    }
}
