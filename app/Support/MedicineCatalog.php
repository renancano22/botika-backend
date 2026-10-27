<?php

namespace App\Support;

use App\Models\Medicine;

/**
 * Botika ng Bayan (Bulan, Sorsogon) medicine list, organized under the Botika's 18 categories.
 *
 * Only the medicine records are imported: generic name, strength, dosage form and category exactly as
 * given in the list (obvious misspellings corrected, e.g. "Ferous" → "Ferrous", "Dicyloverine" →
 * "Dicycloverine"). A strength or dosage form that the list does not give is left blank for staff to
 * fill in. NO stock, batch number or expiration date is created: every new medicine starts as
 * "stock not recorded" until staff record a stock-in (or confirm that there is no stock).
 */
class MedicineCatalog
{
    public const CATEGORIES = [
        'Vitamins/Supplements',
        'Antihypertensives',
        'Antihyperlipidemia',
        'Antibacterial',
        'Anti-Hyperglycemia',
        'Diuretics',
        'Analgesic/Antipyretic',
        'Antihistamine',
        'NSAIDs',
        'Anticough/Asthma',
        'Corticosteroids',
        'Anti-Angina',
        'Blood Thinners',
        'Antihyperuricemia',
        'Gastro',
        'Laxatives',
        'Ointments/Creams',
        'Renal Nutrition',
    ];

    /** Dosage form => unit used to count its stock. */
    public const DOSAGE_FORMS = [
        'Tablet' => 'tablet',
        'Capsule' => 'capsule',
        'Syrup' => 'bottle',
        'Suspension' => 'bottle',
        'Oral Drops' => 'bottle',
        'Nebule' => 'nebule',
        'Cream' => 'tube',
        'Ointment' => 'tube',
    ];

    /** Unit for a medicine whose dosage form is not given yet (staff set the form later). */
    public const UNSPECIFIED_UNIT = 'piece';

    /**
     * Maintenance and antibiotic categories start as "requires prescription" (same rule the system
     * already used for antibiotics, antihypertensives and antidiabetics). Staff can change it per medicine.
     */
    public const PRESCRIPTION_CATEGORIES = [
        'Antihypertensives', 'Antihyperlipidemia', 'Antibacterial', 'Anti-Hyperglycemia', 'Diuretics',
        'Corticosteroids', 'Anti-Angina', 'Blood Thinners', 'Antihyperuricemia', 'Renal Nutrition',
    ];

    /** Old category names used before the catalog => catalog category. */
    public const OLD_CATEGORIES = [
        'Vitamin' => 'Vitamins/Supplements',
        'Vitamins' => 'Vitamins/Supplements',
        'Antihypertensive' => 'Antihypertensives',
        'Antibiotic' => 'Antibacterial',
        'Antibiotics' => 'Antibacterial',
        'Antidiabetic' => 'Anti-Hyperglycemia',
        'Analgesic / Antipyretic' => 'Analgesic/Antipyretic',
        'Analgesic (NSAID)' => 'NSAIDs',
        'Mucolytic' => 'Anticough/Asthma',
        'Bronchodilator' => 'Anticough/Asthma',
        'Antidiarrheal' => 'Gastro',
    ];

    /** @return array<int, array{category: string, generic: string, strength: ?string, form: ?string}> */
    public static function items(): array
    {
        // [category, generic name, strength (null = not given), dosage form (null = not given)]
        $rows = [
            ['Vitamins/Supplements', 'Ascorbic Acid', '500mg', 'Tablet'],
            ['Vitamins/Supplements', 'Ferrous Sulfate + Folic Acid', null, null],
            ['Vitamins/Supplements', 'Ascorbic Acid', '100mg', 'Oral Drops'],
            ['Vitamins/Supplements', 'Ascorbic Acid', '100mg/5ml', 'Syrup'],
            ['Vitamins/Supplements', 'Multivitamin', null, 'Syrup'],

            ['Antihypertensives', 'Losartan', '50mg', 'Tablet'],
            ['Antihypertensives', 'Losartan', '100mg', 'Tablet'],
            ['Antihypertensives', 'Irbesartan', '150mg', null],
            ['Antihypertensives', 'Amlodipine', '5mg', 'Tablet'],
            ['Antihypertensives', 'Amlodipine', '10mg', null],
            ['Antihypertensives', 'Losartan + Hydrochlorothiazide', '50mg/12.5mg', 'Tablet'],
            ['Antihypertensives', 'Methyldopa', '250mg', null],

            ['Antihyperlipidemia', 'Atorvastatin', '10mg', 'Tablet'],
            ['Antihyperlipidemia', 'Atorvastatin', '20mg', 'Tablet'],
            ['Antihyperlipidemia', 'Atorvastatin', '40mg', 'Tablet'],
            ['Antihyperlipidemia', 'Atorvastatin', '80mg', 'Tablet'],
            ['Antihyperlipidemia', 'Simvastatin', '20mg', 'Tablet'],
            ['Antihyperlipidemia', 'Simvastatin', '40mg', 'Tablet'],
            ['Antihyperlipidemia', 'Rosuvastatin', '20mg', 'Tablet'],

            ['Antibacterial', 'Amoxicillin', '250mg', 'Capsule'],
            ['Antibacterial', 'Amoxicillin', '500mg', 'Capsule'],
            ['Antibacterial', 'Cefalexin', '500mg', 'Capsule'],
            ['Antibacterial', 'Amoxicillin', '100mg', 'Oral Drops'],
            ['Antibacterial', 'Amoxicillin', '125mg', 'Suspension'],
            ['Antibacterial', 'Amoxicillin', '250mg', 'Suspension'],
            ['Antibacterial', 'Clarithromycin', '500mg', null],
            ['Antibacterial', 'Clarithromycin', '250mg', 'Suspension'],
            ['Antibacterial', 'Cefixime', '200mg', 'Capsule'],
            ['Antibacterial', 'Ciprofloxacin', '500mg', 'Tablet'],
            ['Antibacterial', 'Cefalexin', '100mg', 'Oral Drops'],
            ['Antibacterial', 'Cefalexin', '250mg', 'Suspension'],
            ['Antibacterial', 'Cloxacillin', '500mg', 'Capsule'],
            ['Antibacterial', 'Clindamycin', '300mg', 'Capsule'],
            ['Antibacterial', 'Metronidazole', '500mg', 'Tablet'],
            ['Antibacterial', 'Metronidazole', '125mg', 'Syrup'],
            ['Antibacterial', 'Cefuroxime', '500mg', 'Tablet'],
            ['Antibacterial', 'Co-Amoxiclav', '625mg', 'Tablet'],
            ['Antibacterial', 'Azithromycin', '500mg', 'Tablet'],

            ['Anti-Hyperglycemia', 'Metformin', '500mg', 'Tablet'],
            ['Anti-Hyperglycemia', 'Gliclazide', '30mg', 'Tablet'],
            ['Anti-Hyperglycemia', 'Gliclazide', '60mg', 'Tablet'],
            ['Anti-Hyperglycemia', 'Gliclazide', '80mg', 'Tablet'],
            ['Anti-Hyperglycemia', 'Glimepiride', '2mg', 'Tablet'],

            ['Diuretics', 'Furosemide', '20mg', 'Tablet'],
            ['Diuretics', 'Furosemide', '40mg', 'Tablet'],

            // Paracetamol 250mg/5ml is on hold (not included yet).
            ['Analgesic/Antipyretic', 'Paracetamol', '100mg', 'Oral Drops'],

            ['Antihistamine', 'Cetirizine', null, 'Oral Drops'],
            ['Antihistamine', 'Diphenhydramine', '50mg', 'Capsule'],
            ['Antihistamine', 'Cetirizine', '10mg', 'Tablet'],

            ['NSAIDs', 'Celecoxib', '200mg', 'Capsule'],
            ['NSAIDs', 'Ibuprofen', '200mg', 'Tablet'],
            ['NSAIDs', 'Mefenamic Acid', '500mg', 'Capsule'],
            ['NSAIDs', 'Mefenamic Acid', '50mg/5ml', 'Suspension'],
            ['NSAIDs', 'Etoricoxib', '90mg', 'Tablet'],

            ['Anticough/Asthma', 'Lagundi', '600mg', 'Tablet'],
            ['Anticough/Asthma', 'Lagundi', '300mg', 'Syrup'],
            ['Anticough/Asthma', 'Salbutamol', '2mg/5ml', 'Syrup'],
            ['Anticough/Asthma', 'Salbutamol', '2mg', 'Tablet'],
            ['Anticough/Asthma', 'Budesonide', null, 'Nebule'],
            ['Anticough/Asthma', 'Salbutamol', null, 'Nebule'],

            ['Corticosteroids', 'Prednisone', '10mg', 'Tablet'],

            ['Anti-Angina', 'Trimetazidine', '35mg', 'Tablet'],

            ['Blood Thinners', 'Aspirin', '80mg', 'Tablet'],
            ['Blood Thinners', 'Clopidogrel', '75mg', 'Tablet'],

            ['Antihyperuricemia', 'Allopurinol', '100mg', 'Tablet'],
            ['Antihyperuricemia', 'Allopurinol', '300mg', 'Tablet'],
            ['Antihyperuricemia', 'Febuxostat', '40mg', 'Tablet'],

            ['Gastro', 'Omeprazole', '40mg', 'Capsule'],
            ['Gastro', 'Ranitidine', '150mg', 'Tablet'],
            ['Gastro', 'Ranitidine', '300mg', 'Tablet'],
            ['Gastro', 'Loperamide', '2mg', 'Capsule'],
            ['Gastro', 'Metoclopramide', '10mg', 'Tablet'],
            ['Gastro', 'Metoclopramide', null, 'Syrup'],
            ['Gastro', 'Domperidone', '10mg', 'Tablet'],
            ['Gastro', 'Dicycloverine', '10mg', 'Tablet'],
            ['Gastro', 'Dicycloverine', null, 'Syrup'],

            ['Laxatives', 'Lactulose', null, 'Syrup'],
            ['Laxatives', 'Bisacodyl', null, 'Tablet'],

            ['Ointments/Creams', 'Clobetasol', null, 'Cream'],
            ['Ointments/Creams', 'Mupirocin', null, 'Ointment'],

            ['Renal Nutrition', 'Ketoanalogue + Essential Amino Acids', '600mg', 'Tablet'],
        ];

        return array_map(fn ($r) => ['category' => $r[0], 'generic' => $r[1], 'strength' => $r[2], 'form' => $r[3]], $rows);
    }

    /** "Amoxicillin 500mg Capsule": the name shown everywhere (requests, dispensing, reports, forecasts). */
    public static function displayName(string $generic, ?string $strength, ?string $form): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([trim($generic), trim((string) $strength), trim((string) $form)]))));
    }

    public static function unitFor(?string $form): string
    {
        return self::DOSAGE_FORMS[$form] ?? self::UNSPECIFIED_UNIT;
    }

    /** Letters and digits only, lower case: "Amoxicillin 500 mg" and "amoxicillin 500mg" are the same. */
    private static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }

    /**
     * Adds the catalog to the medicine records. A medicine that is already in the system (same generic name
     * and strength, and the same dosage form when known) is updated in place, so its stock, requests and
     * dispensing history stay with it. Medicines that are not in the catalog are left as they are (only an
     * old category name is renamed to the matching catalog category). Safe to run more than once.
     *
     * @return array{added: int, matched: int}
     */
    public static function import(): array
    {
        $existing = Medicine::orderBy('medicine_id')->get();
        $claimed = [];
        $added = $matched = 0;

        foreach (self::items() as $item) {
            $display = self::displayName($item['generic'], $item['strength'], $item['form']);
            $match = $existing->first(function (Medicine $m) use ($item, $display, $claimed) {
                if (in_array($m->medicine_id, $claimed, true)) return false;
                if (self::key($m->medicine_name) === self::key($display)) return true;
                if ($item['strength'] === null) return false;
                // e.g. "Amoxicillin 500mg" (unit capsule) added by hand before the catalog
                $sameName = self::key($m->generic_name && $m->strength ? "{$m->generic_name} {$m->strength}" : $m->medicine_name)
                    === self::key("{$item['generic']} {$item['strength']}");
                if (! $sameName) return false;
                $form = $m->dosage_form ?: array_search($m->unit, ['Tablet' => 'tablet', 'Capsule' => 'capsule', 'Nebule' => 'nebule'], true);
                return ! $form || ! $item['form'] || $form === $item['form'];
            });

            if ($match) {
                $claimed[] = $match->medicine_id;
                $match->fill([
                    'generic_name' => $item['generic'],
                    'strength' => $item['strength'],
                    'dosage_form' => $item['form'] ?? $match->dosage_form,
                    'category' => $item['category'],
                ])->save(); // keeps its unit, reorder level, prescription setting, stock and history
                $matched++;
                continue;
            }

            $new = Medicine::create([
                'generic_name' => $item['generic'],
                'strength' => $item['strength'],
                'dosage_form' => $item['form'],
                'category' => $item['category'],
                'unit' => self::unitFor($item['form']),
                'reorder_level' => config('botika.default_reorder_level'),
                'requires_prescription' => in_array($item['category'], self::PRESCRIPTION_CATEGORIES, true),
            ]); // stock not recorded: no batch is created
            $claimed[] = $new->medicine_id;
            $added++;
        }

        // Medicines outside the catalog keep their records; only old category names are updated.
        foreach (self::OLD_CATEGORIES as $old => $new) {
            Medicine::where('category', $old)->whereNotIn('medicine_id', $claimed)->update(['category' => $new]);
        }

        return ['added' => $added, 'matched' => $matched];
    }
}
