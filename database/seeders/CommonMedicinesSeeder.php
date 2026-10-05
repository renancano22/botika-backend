<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Illuminate\Database\Seeder;

/**
 * Adds a starter list of common Botika ng Bayan medicines (records only, NO stock).
 * Stock is added through Inventory > Stock-in with the real quantity and expiry date.
 *
 *     php artisan db:seed --class=CommonMedicinesSeeder
 *
 * Safe to run more than once: a medicine is skipped if one with the same generic name
 * already exists (e.g. "Amoxicillin" already added by hand).
 * Edit names, units and reorder levels later in the Medicines page to match the Botika.
 */
class CommonMedicinesSeeder extends Seeder
{
    public function run(): void
    {
        // [generic name, strength, category, unit, reorder level, description]
        $medicines = [
            ['Paracetamol', '500mg', 'Analgesic / Antipyretic', 'tablet', 100, 'For fever and mild to moderate pain.'],
            ['Amoxicillin', '500mg', 'Antibiotic', 'capsule', 60, 'For bacterial infections. Requires prescription.'],
            ['Mefenamic Acid', '500mg', 'Analgesic (NSAID)', 'capsule', 50, 'For pain such as toothache, headache and menstrual pain.'],
            ['Ibuprofen', '200mg', 'Analgesic (NSAID)', 'tablet', 50, 'For pain, fever and inflammation.'],
            ['Cetirizine', '10mg', 'Antihistamine', 'tablet', 40, 'For allergies, itchiness and allergic rhinitis.'],
            ['Carbocisteine', '500mg', 'Mucolytic', 'capsule', 40, 'For cough with phlegm.'],
            ['Salbutamol', '2mg', 'Bronchodilator', 'tablet', 30, 'For asthma and difficulty of breathing. Requires prescription.'],
            ['Losartan', '50mg', 'Antihypertensive', 'tablet', 60, 'Maintenance medicine for high blood pressure. Requires prescription.'],
            ['Amlodipine', '5mg', 'Antihypertensive', 'tablet', 60, 'Maintenance medicine for high blood pressure. Requires prescription.'],
            ['Metformin', '500mg', 'Antidiabetic', 'tablet', 60, 'Maintenance medicine for type 2 diabetes. Requires prescription.'],
            ['Loperamide', '2mg', 'Antidiarrheal', 'capsule', 30, 'For acute diarrhea.'],
            ['Oral Rehydration Salts', '', 'Electrolyte replacement', 'sachet', 30, 'For dehydration caused by diarrhea or vomiting.'],
            ['Ascorbic Acid', '500mg', 'Vitamin', 'tablet', 50, 'Vitamin C supplement.'],
        ];

        $added = 0;
        foreach ($medicines as [$generic, $strength, $category, $unit, $reorder, $description]) {
            if (Medicine::where('medicine_name', 'like', $generic . '%')->exists()) {
                $this->command?->line("Skipped {$generic} (already in the list).");
                continue;
            }

            Medicine::create([
                'medicine_name' => trim("{$generic} {$strength}"),
                'category' => $category,
                'unit' => $unit,
                'reorder_level' => $reorder,
                'description' => $description,
            ]);
            $added++;
        }

        $this->command?->info("{$added} medicine(s) added. Add their stock in Inventory > Stock-in.");
    }
}
