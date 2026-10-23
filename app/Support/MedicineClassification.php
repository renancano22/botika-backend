<?php

namespace App\Support;

/**
 * Verified medicine classification: which of the Botika's 18 categories (MedicineCatalog::CATEGORIES) an active
 * ingredient belongs to. Built from:
 *  1. the Botika ng Bayan medicine list (MedicineCatalog::items()), the categories the Botika itself uses; and
 *  2. common generic medicines placed in the category matching their standard therapeutic class
 *     (WHO ATC classification), e.g. amoxicillin = antibacterial, prednisone = corticosteroid.
 * Some ingredients fit more than one category (aspirin is a blood thinner at 80mg and a pain reliever at higher
 * doses; budesonide is an inhaled corticosteroid for asthma). Creams and ointments may always be filed under
 * "Ointments/Creams", as the Botika does.
 *
 * A category is only rejected when the ingredient is in this reference and the category is not one of its
 * categories (a confirmed mismatch). A medicine that is not in the reference is not blocked.
 */
class MedicineClassification
{
    /** Category => what medicines in it are, for the warning ("Amoxicillin is an antibiotic"). */
    public const DESCRIPTIONS = [
        'Vitamins/Supplements' => 'a vitamin or supplement',
        'Antihypertensives' => 'a blood pressure medicine (antihypertensive)',
        'Antihyperlipidemia' => 'a cholesterol-lowering medicine',
        'Antibacterial' => 'an antibiotic (antibacterial)',
        'Anti-Hyperglycemia' => 'a diabetes medicine (anti-hyperglycemic)',
        'Diuretics' => 'a diuretic',
        'Analgesic/Antipyretic' => 'a pain and fever reliever (analgesic/antipyretic)',
        'Antihistamine' => 'an antihistamine (for allergies)',
        'NSAIDs' => 'a non-steroidal anti-inflammatory drug (NSAID)',
        'Anticough/Asthma' => 'a cough or asthma medicine',
        'Corticosteroids' => 'a corticosteroid',
        'Anti-Angina' => 'an anti-angina medicine',
        'Blood Thinners' => 'a blood thinner',
        'Antihyperuricemia' => 'a uric acid-lowering medicine (for gout)',
        'Gastro' => 'a stomach or digestive medicine',
        'Laxatives' => 'a laxative',
        'Ointments/Creams' => 'a cream or ointment',
        'Renal Nutrition' => 'a kidney (renal) nutrition supplement',
    ];

    /** Common generics by category (standard therapeutic class), in addition to the Botika's own list. */
    private const COMMON = [
        'Antibacterial' => [
            'Amoxicillin', 'Ampicillin', 'Cefalexin', 'Cefaclor', 'Cefadroxil', 'Cefixime', 'Cefuroxime', 'Cefpodoxime',
            'Ceftriaxone', 'Ciprofloxacin', 'Levofloxacin', 'Ofloxacin', 'Clarithromycin', 'Azithromycin', 'Erythromycin',
            'Clindamycin', 'Cloxacillin', 'Dicloxacillin', 'Doxycycline', 'Tetracycline', 'Metronidazole', 'Nitrofurantoin',
            'Co-Amoxiclav', 'Sulfamethoxazole + Trimethoprim', 'Phenoxymethylpenicillin',
        ],
        'Antihypertensives' => [
            'Losartan', 'Irbesartan', 'Valsartan', 'Telmisartan', 'Candesartan', 'Amlodipine', 'Nifedipine', 'Felodipine',
            'Captopril', 'Enalapril', 'Lisinopril', 'Metoprolol', 'Atenolol', 'Carvedilol', 'Bisoprolol', 'Propranolol',
            'Methyldopa', 'Hydralazine', 'Clonidine', 'Losartan + Hydrochlorothiazide',
        ],
        'Antihyperlipidemia' => ['Atorvastatin', 'Simvastatin', 'Rosuvastatin', 'Pravastatin', 'Lovastatin', 'Fenofibrate', 'Gemfibrozil', 'Ezetimibe'],
        'Anti-Hyperglycemia' => ['Metformin', 'Gliclazide', 'Glimepiride', 'Glibenclamide', 'Glipizide', 'Sitagliptin', 'Vildagliptin', 'Pioglitazone'],
        'Diuretics' => ['Furosemide', 'Spironolactone'],
        'Analgesic/Antipyretic' => ['Paracetamol'],
        'Antihistamine' => ['Cetirizine', 'Levocetirizine', 'Loratadine', 'Desloratadine', 'Diphenhydramine', 'Chlorphenamine', 'Fexofenadine', 'Hydroxyzine'],
        'NSAIDs' => ['Ibuprofen', 'Mefenamic Acid', 'Celecoxib', 'Etoricoxib', 'Naproxen', 'Diclofenac', 'Meloxicam', 'Piroxicam', 'Ketorolac'],
        'Anticough/Asthma' => [
            'Lagundi', 'Salbutamol', 'Ambroxol', 'Carbocisteine', 'Guaifenesin', 'Dextromethorphan', 'Montelukast',
            'Ipratropium', 'Salbutamol + Ipratropium', 'Theophylline', 'Butamirate',
        ],
        'Corticosteroids' => ['Prednisone', 'Prednisolone', 'Dexamethasone', 'Methylprednisolone', 'Hydrocortisone'],
        'Anti-Angina' => ['Trimetazidine', 'Isosorbide Mononitrate', 'Isosorbide Dinitrate', 'Nitroglycerin'],
        'Blood Thinners' => ['Clopidogrel', 'Warfarin', 'Rivaroxaban', 'Apixaban'],
        'Antihyperuricemia' => ['Allopurinol', 'Febuxostat'],
        'Gastro' => [
            'Omeprazole', 'Esomeprazole', 'Pantoprazole', 'Lansoprazole', 'Ranitidine', 'Famotidine', 'Loperamide',
            'Metoclopramide', 'Domperidone', 'Dicycloverine', 'Hyoscine', 'Simethicone', 'Sucralfate',
            'Oral Rehydration Salts', 'Aluminum Hydroxide + Magnesium Hydroxide',
        ],
        'Laxatives' => ['Lactulose', 'Bisacodyl', 'Senna', 'Docusate'],
        'Vitamins/Supplements' => [
            'Ascorbic Acid', 'Ferrous Sulfate', 'Ferrous Sulfate + Folic Acid', 'Folic Acid', 'Multivitamin',
            'Vitamin B Complex', 'Zinc', 'Calcium Carbonate', 'Cholecalciferol',
        ],
        'Ointments/Creams' => ['Clotrimazole', 'Silver Sulfadiazine', 'Fusidic Acid'],
    ];

    /** Ingredients that belong to more than one category. */
    private const ALSO = [
        'Aspirin' => ['Blood Thinners', 'Analgesic/Antipyretic', 'NSAIDs'],
        'Hydrochlorothiazide' => ['Diuretics', 'Antihypertensives'],
        'Indapamide' => ['Diuretics', 'Antihypertensives'],
        'Budesonide' => ['Anticough/Asthma', 'Corticosteroids'],
        'Fluticasone' => ['Anticough/Asthma', 'Corticosteroids'],
        'Clobetasol' => ['Ointments/Creams', 'Corticosteroids'],
        'Betamethasone' => ['Corticosteroids', 'Ointments/Creams'],
        'Mupirocin' => ['Ointments/Creams', 'Antibacterial'],
    ];

    /** Topical dosage forms: may always be filed under "Ointments/Creams". */
    private const TOPICAL_FORMS = ['Cream', 'Ointment'];

    /** @return array<string, string[]> ingredient key => categories */
    public static function reference(): array
    {
        static $ref = null;
        if ($ref !== null) return $ref;
        $ref = [];
        $add = function (string $generic, string $category) use (&$ref) {
            $key = MedicineIdentity::ingredientKey($generic);
            $ref[$key] = array_values(array_unique([...($ref[$key] ?? []), $category]));
        };
        foreach (MedicineCatalog::items() as $item) $add($item['generic'], $item['category']); // the Botika's own list
        foreach (self::COMMON as $category => $generics) foreach ($generics as $g) $add($g, $category);
        foreach (self::ALSO as $g => $categories) foreach ($categories as $c) $add($g, $c);
        return $ref;
    }

    /** The categories this medicine belongs to, or null when it is not in the reference (nothing to check). */
    public static function categoriesFor(string $generic, ?string $form = null): ?array
    {
        $categories = self::reference()[MedicineIdentity::ingredientKey($generic)] ?? null;
        if ($categories === null) return null;
        if (in_array($form, self::TOPICAL_FORMS, true)) $categories[] = 'Ointments/Creams';
        return array_values(array_unique($categories));
    }

    /** null when the category fits (or the medicine is not in the reference); otherwise the warning to show. */
    public static function mismatch(string $generic, ?string $form, string $category): ?string
    {
        $expected = self::categoriesFor($generic, $form);
        if ($expected === null || in_array($category, $expected, true)) return null;
        $name = trim($generic);
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1); // "amoxicillin" → "Amoxicillin"
        $what = self::DESCRIPTIONS[$expected[0]] ?? $expected[0];
        $where = count($expected) === 1 ? $expected[0] : implode(' or ', $expected);
        return "The selected category appears incorrect: {$name} is {$what}, so it belongs under {$where}, not {$category}. Please choose the correct category.";
    }
}
