<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first administrator and pharmacy staff accounts.
 * CHANGE THESE PASSWORDS after the first login (Users page).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'admin@bulanbotika.local'], [
            'name' => 'Botika Administrator',
            'password_hash' => 'admin12345',
            'role' => User::ROLE_ADMIN,
        ]);

        User::firstOrCreate(['email' => 'staff@bulanbotika.local'], [
            'name' => 'Pharmacy Staff',
            'password_hash' => 'staff12345',
            'role' => User::ROLE_STAFF,
        ]);
    }
}
