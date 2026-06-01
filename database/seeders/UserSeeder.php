<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's users.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'danielg1177'],
            ['password' => '2840924'],
        );

        User::updateOrCreate(
            ['username' => 'testuser'],
            ['password' => '2840924'],
        );
    }
}
