<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use App\Support\MedicineIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Duplicate medicine detection and medicine category validation (Add / Edit medicine). */
class MedicineValidationTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function headers(?User $user = null): array
    {
        return ['Authorization' => 'Bearer ' . ($user ?? User::factory()->create())->createToken('t')->plainTextToken];
    }

    private function medicine(array $fields): array
    {
        return $fields + ['category' => 'Antibacterial', 'unit' => 'capsule', 'reorder_level' => 20, 'requires_prescription' => true];
    }

    public function test_duplicate_medicines_are_detected(): void
    {
        $h = $this->headers();
        $first = $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'Capsule']), $h)
            ->assertCreated()->json('medicine_id');
        Inventory::create(['medicine_id' => $first, 'quantity' => 40, 'expiration_date' => now()->addYear()->toDateString()]);

        // The same medicine written differently is refused, pointing to the existing record (for its stock-in).
        foreach ([
            ['generic_name' => 'amoxicillin', 'strength' => '500 MG', 'dosage_form' => 'Capsule'],   // case and spacing
            ['generic_name' => 'Amoxicillin', 'strength' => '0.5g', 'dosage_form' => 'Capsule'],     // same strength in grams
        ] as $same) {
            $this->postJson('/api/medicines', $this->medicine($same), $h)->assertStatus(422)
                ->assertJsonValidationErrors('generic_name')
                ->assertJsonPath('duplicate.medicine_id', $first)->assertJsonPath('duplicate.exact', true)
                ->assertJsonPath('duplicate.medicine_code', Medicine::codeFor($first))
                ->assertJsonPath('message', 'Amoxicillin 500mg Capsule (' . Medicine::codeFor($first) . ') is already registered in the medicine list. '
                    . 'Registering it again would split its stock and history, so record a stock-in for the existing medicine instead.');
        }
        $this->assertSame(1, Medicine::count());
        $this->assertSame(40, (int) Inventory::where('medicine_id', $first)->sum('quantity')); // stock not touched

        // Other names of the same ingredient, and combinations in another order, are the same medicine.
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Cefalexin', 'strength' => '500mg', 'dosage_form' => 'Capsule']), $h)->assertCreated();
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Cephalexin', 'strength' => '500mg', 'dosage_form' => 'Capsule']), $h)
            ->assertStatus(422)->assertJsonPath('duplicate.medicine_name', 'Cefalexin 500mg Capsule');
        $combo = ['strength' => '50mg/12.5mg', 'dosage_form' => 'Tablet', 'category' => 'Antihypertensives', 'unit' => 'tablet'];
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Losartan + Hydrochlorothiazide'] + $combo), $h)->assertCreated();
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Hydrochlorothiazide + Losartan'] + $combo), $h)->assertStatus(422);

        // A different strength or dosage form is a different medicine.
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '250mg', 'dosage_form' => 'Capsule']), $h)->assertCreated();
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '250mg', 'dosage_form' => 'Suspension', 'unit' => 'bottle']), $h)->assertCreated();

        // A record missing its strength cannot be told apart yet: complete it first, then add the other one.
        $drops = $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Cetirizine', 'dosage_form' => 'Oral Drops', 'category' => 'Antihistamine', 'unit' => 'bottle']), $h)
            ->assertCreated()->json('medicine_id');
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Cetirizine', 'strength' => '2.5mg/ml', 'dosage_form' => 'Oral Drops', 'category' => 'Antihistamine', 'unit' => 'bottle']), $h)
            ->assertStatus(422)->assertJsonPath('duplicate.exact', false)
            ->assertJsonFragment(['message' => 'This may be the same medicine as Cetirizine Oral Drops (' . Medicine::codeFor($drops) . '), which is registered without a strength. '
                . 'If it is the same medicine, use that record (record a stock-in for it). If it is a different formulation, first add the strength to '
                . Medicine::codeFor($drops) . ' so the two can be told apart.']);
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Cetirizine', 'strength' => '10mg', 'dosage_form' => 'Tablet', 'category' => 'Antihistamine', 'unit' => 'tablet']), $h)
            ->assertCreated(); // different dosage form

        // Editing: a medicine cannot be changed into another registered one; its other details can always be saved.
        $other = Medicine::where('medicine_name', 'Amoxicillin 250mg Capsule')->value('medicine_id');
        $this->putJson("/api/medicines/{$other}", $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'Capsule']), $h)
            ->assertStatus(422)->assertJsonPath('duplicate.medicine_id', $first);
        $this->putJson("/api/medicines/{$first}", $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'Capsule', 'reorder_level' => 30]), $h)
            ->assertOk()->assertJsonPath('reorder_level', 30);

        // The database itself refuses a second record with the same identity (two people saving at the same time).
        $this->expectException(QueryException::class);
        Medicine::create(['generic_name' => 'AMOXICILLIN', 'strength' => '500 mg', 'dosage_form' => 'Capsule', 'category' => 'Antibacterial', 'unit' => 'capsule', 'reorder_level' => 5]);
    }

    public function test_category_must_match_the_verified_classification(): void
    {
        $h = $this->headers();
        // Amoxicillin is an antibiotic: filing it under Corticosteroids is refused with an explanation.
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'Capsule', 'category' => 'Corticosteroids']), $h)
            ->assertStatus(422)->assertJsonPath('errors.category.0',
                'The selected category appears incorrect: Amoxicillin is an antibiotic (antibacterial), so it belongs under Antibacterial, not Corticosteroids. Please choose the correct category.');
        $this->assertSame(0, Medicine::count());

        // Medicines in more than one category, creams filed as Ointments/Creams, and medicines not in the reference.
        $ok = [
            ['generic_name' => 'Aspirin', 'strength' => '80mg', 'dosage_form' => 'Tablet', 'category' => 'Blood Thinners', 'unit' => 'tablet'],
            ['generic_name' => 'Aspirin', 'strength' => '325mg', 'dosage_form' => 'Tablet', 'category' => 'Analgesic/Antipyretic', 'unit' => 'tablet'],
            ['generic_name' => 'Hydrocortisone', 'strength' => '1%', 'dosage_form' => 'Cream', 'category' => 'Ointments/Creams', 'unit' => 'tube'],
            ['generic_name' => 'Vitamin C', 'strength' => '500mg', 'dosage_form' => 'Tablet', 'category' => 'Vitamins/Supplements', 'unit' => 'tablet'],
            ['generic_name' => 'Tawa-tawa', 'strength' => '250mg', 'dosage_form' => 'Capsule', 'category' => 'Gastro', 'unit' => 'capsule'], // not in the reference
        ];
        foreach ($ok as $m) $this->postJson('/api/medicines', $this->medicine($m), $h)->assertCreated();
        $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Prednisone', 'strength' => '10mg', 'dosage_form' => 'Tablet', 'category' => 'NSAIDs', 'unit' => 'tablet']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('category');

        // Editing a medicine into a wrong category is refused too.
        $id = Medicine::where('generic_name', 'Aspirin')->where('strength', '80mg')->value('medicine_id');
        $this->putJson("/api/medicines/{$id}", $this->medicine(['generic_name' => 'Aspirin', 'strength' => '80mg', 'dosage_form' => 'Tablet', 'category' => 'Antibacterial', 'unit' => 'tablet']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_check_while_typing(): void
    {
        $h = $this->headers();
        $id = $this->postJson('/api/medicines', $this->medicine(['generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'Capsule']), $h)->json('medicine_id');

        $this->getJson('/api/medicines/check?generic_name=amoxicillin&strength=500%20mg&dosage_form=Capsule&category=Corticosteroids', $h)->assertOk()
            ->assertJsonPath('duplicate.medicine_id', $id)->assertJsonPath('categories', ['Antibacterial'])
            ->assertJsonPath('category_message', 'The selected category appears incorrect: Amoxicillin is an antibiotic (antibacterial), so it belongs under Antibacterial, not Corticosteroids. Please choose the correct category.');
        // Editing the same medicine: it is not a duplicate of itself.
        $this->getJson("/api/medicines/check?generic_name=Amoxicillin&strength=500mg&dosage_form=Capsule&category=Antibacterial&ignore={$id}", $h)->assertOk()
            ->assertJsonPath('duplicate', null)->assertJsonPath('category_message', null);

        // Only for those who may add and edit medicines.
        $admin = User::factory()->admin()->create();
        $this->putJson('/api/permissions/staff', ['permissions' => ['medicines.manage' => false]], $this->headers($admin))->assertOk();
        $this->getJson('/api/medicines/check?generic_name=Amoxicillin', $this->headers())->assertForbidden();
    }

    public function test_normalization(): void
    {
        $this->assertSame('amoxicillin|500mg|capsule', MedicineIdentity::key('Amoxicillin', '0.5 g', 'Capsule'));
        $this->assertSame('ascorbicacid|100mg/5ml|syrup', MedicineIdentity::key('Vitamin C', '100 mg / 5 mL', 'Syrup'));
        $this->assertSame('paracetamol|500mg|tablet', MedicineIdentity::key(null, null, null, 'Paracetamol 500mg Tablet')); // name only
        $this->assertSame('hydrochlorothiazide+losartan|50mg/12.5mg|tablet', MedicineIdentity::key('Losartan + Hydrochlorothiazide', '50mg/12.50mg', 'Tablet'));
    }
}
