<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * BulanBotikaCare does not seed any accounts or sample records, so the database
 * only ever holds real Botika ng Bayan data.
 *
 * To create the first administrator account, run:
 *     php artisan botika:create-admin
 * The administrator then creates pharmacy staff accounts in "User Accounts",
 * and residents register themselves on the Register page.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('No sample data is seeded. Run "php artisan botika:create-admin" to create the administrator.');
    }
}
