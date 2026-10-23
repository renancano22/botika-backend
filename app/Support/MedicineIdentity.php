<?php

namespace App\Support;

use App\Models\Medicine;

/**
 * Duplicate medicine detection: tells whether two medicine records describe the same medicine.
 *
 * A medicine is identified by its active ingredient(s), strength and dosage form, compared in a normalized way:
 *  - ingredient: lower case, letters and digits only, known alternative names merged ("Cephalexin" = "Cefalexin",
 *    "Vitamin C" = "Ascorbic Acid"), and combinations in any order ("Losartan + Hydrochlorothiazide" =
 *    "Hydrochlorothiazide + Losartan");
 *  - strength: spaces and case ignored, the same unit used ("0.5 g" = "500mg", "100 mg / 5 mL" = "100mg/5ml");
 *  - dosage form: one of the dosage forms in the list.
 * A different strength or dosage form is a different medicine and can be registered separately.
 */
class MedicineIdentity
{
    /** Alternative names of the same active ingredient => the name used in the Botika's medicine list. */
    public const ALIASES = [
        'cephalexin' => 'cefalexin',
        'acetaminophen' => 'paracetamol',
        'albuterol' => 'salbutamol',
        'vitaminc' => 'ascorbicacid',
        'dicyclomine' => 'dicycloverine',
        'chlorpheniramine' => 'chlorphenamine',
        'chlorpheniraminemaleate' => 'chlorphenamine',
        'glyburide' => 'glibenclamide',
        'amoxicillinclavulanicacid' => 'coamoxiclav',
        'amoxicillinclavulanate' => 'coamoxiclav',
        'amoxicillinclavulanatepotassium' => 'coamoxiclav',
        'cotrimoxazole' => 'sulfamethoxazoletrimethoprim',
        'ferroussulphate' => 'ferroussulfate',
        'multivitamins' => 'multivitamin',
        'hyoscinenbutylbromide' => 'hyoscine',
        'butylscopolamine' => 'hyoscine',
        'ors' => 'oralrehydrationsalts',
    ];

    /** Normalized active ingredient(s): "losartan+hydrochlorothiazide" (combinations sorted). */
    public static function ingredientKey(string $generic): string
    {
        $text = strtolower(trim($generic));
        $whole = preg_replace('/[^a-z0-9]/', '', $text);
        if (isset(self::ALIASES[$whole])) {
            return self::ALIASES[$whole];
        }
        $parts = preg_split('/\s*(?:\+|\/|&|\band\b|\bwith\b)\s*/', $text) ?: [$text];
        $keys = array_values(array_filter(array_map(function ($p) {
            $k = preg_replace('/[^a-z0-9]/', '', $p);
            return self::ALIASES[$k] ?? $k;
        }, $parts)));
        sort($keys);
        $key = implode('+', $keys);
        return self::ALIASES[str_replace('+', '', $key)] ?? $key;
    }

    /** Normalized strength: "500mg", "100mg/5ml", "1%" ('' when not given). */
    public static function strengthKey(?string $strength): string
    {
        $text = strtolower(trim((string) $strength));
        if ($text === '') return '';
        $text = str_replace(['µg', 'μg'], 'mcg', $text);
        $text = preg_replace(['/\bmilligrams?\b/', '/\bmicrograms?\b/', '/\bgrams?\b/', '/\bmillilit(?:er|re)s?\b/', '/\bunits?\b/'], ['mg', 'mcg', 'g', 'ml', 'iu'], $text);
        $text = preg_replace('/\s+/', '', $text);

        // Each part of "100mg/5ml": same unit (g → mg, mcg stays), no trailing zeros ("500.0" → "500").
        $parts = array_map(function (string $part) {
            if (! preg_match('/^(\d+(?:[.,]\d+)?)([a-z%]*)$/', $part, $m)) return $part;
            $n = (float) str_replace(',', '.', $m[1]);
            $unit = $m[2];
            if ($unit === 'g') { $n *= 1000; $unit = 'mg'; }
            $num = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
            return $num . $unit;
        }, explode('/', $text));

        return implode('/', $parts);
    }

    public static function formKey(?string $form): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(trim((string) $form)));
    }

    /**
     * The parts of a medicine for comparing. A medicine added with a name only ("Paracetamol 500mg Tablet")
     * has its strength and dosage form read from the name.
     *
     * @return array{ingredient: string, strength: string, form: string}
     */
    public static function parts(?string $generic, ?string $strength, ?string $form, ?string $name = null): array
    {
        $generic = trim((string) ($generic ?: $name));
        if (blank($strength) && blank($form) && $generic !== '') {
            // Read "Paracetamol 500mg Tablet" / "Paracetamol 500mg" when the parts were never filled in.
            $forms = implode('|', array_map('preg_quote', array_keys(MedicineCatalog::DOSAGE_FORMS)));
            if (preg_match("/^(.*?)\\s+($forms)$/i", $generic, $m)) { $generic = $m[1]; $form = $m[2]; }
            if (preg_match('/^(.*?)\s+(\d[\d.,]*\s*(?:mg|g|mcg|ml|iu|%)(?:\s*\/\s*\d[\d.,]*\s*(?:mg|g|mcg|ml))?)$/i', $generic, $m)) {
                $generic = $m[1]; $strength = $m[2];
            }
        }
        return [
            'ingredient' => self::ingredientKey($generic),
            'strength' => self::strengthKey($strength),
            'form' => self::formKey($form),
        ];
    }

    /** The value kept in medicines.identity_key (unique): "amoxicillin|500mg|capsule". */
    public static function key(?string $generic, ?string $strength, ?string $form, ?string $name = null): string
    {
        return implode('|', self::parts($generic, $strength, $form, $name));
    }

    /**
     * An already registered medicine that is the same as (or cannot be told apart from) this one, or null.
     *  - exact: same ingredient, strength and dosage form;
     *  - not exact: same ingredient, and the strength or dosage form is missing on one of them while the rest
     *    matches, so the records cannot be told apart yet.
     *
     * @return array{medicine: Medicine, exact: bool}|null
     */
    public static function findDuplicate(?string $generic, ?string $strength, ?string $form, ?int $ignoreId = null): ?array
    {
        $new = self::parts($generic, $strength, $form);
        if ($new['ingredient'] === '') return null;

        $partial = null;
        foreach (Medicine::query()->when($ignoreId, fn ($q) => $q->where('medicine_id', '!=', $ignoreId))->orderBy('medicine_id')->get() as $m) {
            $old = self::parts($m->generic_name, $m->strength, $m->dosage_form, $m->medicine_name);
            if ($old['ingredient'] !== $new['ingredient']) continue;
            if ($old['strength'] === $new['strength'] && $old['form'] === $new['form']) {
                return ['medicine' => $m, 'exact' => true];
            }
            $strengthOk = $old['strength'] === $new['strength'] || $old['strength'] === '' || $new['strength'] === '';
            $formOk = $old['form'] === $new['form'] || $old['form'] === '' || $new['form'] === '';
            if ($strengthOk && $formOk) {
                $partial ??= ['medicine' => $m, 'exact' => false];
            }
        }
        return $partial;
    }

    /** Professional explanation of a duplicate, for the person adding or editing the medicine. */
    public static function message(array $duplicate, bool $canStockIn = true): string
    {
        /** @var Medicine $m */
        $m = $duplicate['medicine'];
        $ref = "{$m->medicine_name} ({$m->code()})";
        if ($duplicate['exact']) {
            return "{$ref} is already registered in the medicine list. Registering it again would split its stock and history"
                . ($canStockIn ? ', so record a stock-in for the existing medicine instead.' : '. Please use the existing medicine record.');
        }
        $missing = blank($m->strength) && blank($m->dosage_form) ? 'strength and dosage form'
            : (blank($m->strength) ? 'strength' : (blank($m->dosage_form) ? 'dosage form' : null));
        return $missing
            ? "This may be the same medicine as {$ref}, which is registered without a {$missing}. If it is the same medicine, use that record"
              . ($canStockIn ? ' (record a stock-in for it)' : '') . ". If it is a different formulation, first add the {$missing} to {$m->code()} so the two can be told apart."
            : "This may be the same medicine as {$ref}. Enter the strength and dosage form so a different formulation can be told apart, or use the existing record"
              . ($canStockIn ? ' (record a stock-in for it)' : '') . '.';
    }
}
