<?php

namespace Database\Seeders;

use App\Models\Dispensing;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA FOR TESTING ONLY (php artisan db:seed --class=DemoDataSeeder).
 * Creates sample medicines, stock batches, one resident and 12 months of
 * dispensing history so the reports and demand forecasting have data to show.
 * Do not run this on the real Botika ng Bayan database.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        mt_srand(2026);

        $staff = User::where('role', User::ROLE_STAFF)->first();

        $user = User::firstOrCreate(['email' => 'resident@bulanbotika.local'], [
            'name' => 'Juan Dela Cruz', 'password_hash' => 'resident123', 'role' => User::ROLE_RESIDENT,
        ]);
        $resident = Resident::firstOrCreate(['user_id' => $user->user_id], [
            'name' => 'Juan Dela Cruz', 'address' => 'Zone 1, Bulan, Sorsogon',
            'contact_no' => '09171234567', 'qr_code' => 'TEMP-DEMO',
        ]);
        $resident->update(['qr_code' => Resident::makePatientId($resident->resident_id)]);

        // [name, category, unit, reorder level, average monthly demand, monthly trend]
        $medicines = [
            ['Paracetamol 500mg', 'Analgesic', 'tablet', 100, 220, 4],
            ['Amoxicillin 500mg', 'Antibiotic', 'capsule', 80, 150, 2],
            ['Losartan 50mg', 'Antihypertensive', 'tablet', 60, 120, 5],
            ['Amlodipine 5mg', 'Antihypertensive', 'tablet', 60, 110, 3],
            ['Metformin 500mg', 'Antidiabetic', 'tablet', 60, 130, 6],
            ['Cetirizine 10mg', 'Antihistamine', 'tablet', 40, 70, 0],
            ['Salbutamol 2mg', 'Bronchodilator', 'tablet', 30, 50, -1],
            ['Oral Rehydration Salts', 'Electrolyte', 'sachet', 30, 45, 1],
            ['Ascorbic Acid 500mg', 'Vitamin', 'tablet', 50, 90, 2],
            ['Mefenamic Acid 500mg', 'Analgesic', 'capsule', 40, 60, 0],
        ];

        foreach ($medicines as [$name, $category, $unit, $reorder, $avg, $trend]) {
            $m = Medicine::firstOrCreate(['medicine_name' => $name], [
                'category' => $category, 'unit' => $unit, 'reorder_level' => $reorder,
                'description' => "{$name} ({$category})",
            ]);

            // Stock batches: one healthy, one near expiry.
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => mt_rand(0, 3) === 0 ? mt_rand(5, $reorder) : mt_rand($avg, $avg * 2), 'expiration_date' => now()->addMonths(mt_rand(6, 18))->toDateString()]);
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => mt_rand(10, 40), 'expiration_date' => now()->addDays(mt_rand(5, 28))->toDateString()]);

            // 12 months of dispensing history (about 4 transactions a month).
            for ($k = 12; $k >= 1; $k--) {
                $month = now()->startOfMonth()->subMonths($k);
                $monthTotal = max(5, $avg + $trend * (12 - $k) + mt_rand(-15, 15));
                $parts = 4;
                for ($p = 0; $p < $parts; $p++) {
                    $qty = intdiv($monthTotal, $parts) + ($p === 0 ? $monthTotal % $parts : 0);
                    $date = $month->copy()->addDays(mt_rand(0, 27))->setTime(mt_rand(8, 16), mt_rand(0, 59));
                    $req = MedicineRequest::create([
                        'resident_id' => $resident->resident_id, 'request_type' => 'medicine',
                        'request_date' => $date, 'status' => 'dispensed',
                        'reviewed_by' => $staff?->user_id, 'reviewed_at' => $date, 'remarks' => 'Sample data',
                    ]);
                    $req->items()->create(['medicine_id' => $m->medicine_id, 'quantity' => $qty]);
                    $d = Dispensing::create(['request_id' => $req->request_id, 'dispensed_by' => $staff?->user_id, 'dispensed_at' => $date]);
                    $d->items()->create(['medicine_id' => $m->medicine_id, 'quantity' => $qty]);
                }
            }
        }
    }
}
