<?php

namespace Database\Seeders;

use App\Support\MedicineCatalog;
use Illuminate\Database\Seeder;

/**
 * Adds the Botika ng Bayan medicine list (records only, NO stock). It also runs automatically
 * with `php artisan migrate`; run it again any time (it skips medicines already in the system):
 *
 *     php artisan db:seed --class=BotikaMedicineListSeeder
 */
class BotikaMedicineListSeeder extends Seeder
{
    public function run(): void
    {
        $r = MedicineCatalog::import();
        $this->command?->info("{$r['added']} medicine(s) added, {$r['matched']} already in the system updated. Record their stock in Inventory > Stock-in.");
    }
}
