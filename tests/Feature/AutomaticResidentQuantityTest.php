<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Resident;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticResidentQuantityTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sms.semaphore.api_key' => '']);
        $reg = $this->postJson('/api/register', ['name' => 'Ana Dela Peña', 'barangay' => 'Gate', 'contact_no' => '09171234567',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'])->assertCreated();
        $this->headers = ['Authorization' => 'Bearer ' . $reg->json('token')];
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function medicine(?string $form = 'Tablet', string $unit = 'tablet', int $stock = 20, ?string $legacyName = null): Medicine
    {
        $m = Medicine::create(['medicine_name' => $legacyName ?? 'Preset medicine ' . ++$this->counter,
            'dosage_form' => $form, 'unit' => $unit, 'category' => 'Medicine', 'requires_prescription' => false]);
        Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => $stock, 'expiration_date' => now()->addYear()->toDateString()]);
        return $m;
    }

    private function submit(array $items, string $type = 'medicine')
    {
        return $this->postJson('/api/requests', ['request_type' => $type, 'items' => $items], $this->headers);
    }

    public function test_tablets_and_capsules_default_to_ten_using_form_unit_or_legacy_name(): void
    {
        foreach ([['Tablet', 'tablet', null], ['Capsule', 'capsule', null], ['Film-coated tablet', 'piece', null],
            [null, 'tablets', null], [null, 'capsules', null], [null, 'piece', 'Legacy medicine 5mg Tablet']] as [$form, $unit, $name]) {
            $m = $this->medicine($form, $unit, 20, $name);
            $this->submit([['medicine_id' => $m->medicine_id]])->assertCreated()->assertJsonPath('items.0.quantity', 10);
            $this->assertSame(20, app(InventoryService::class)->availableStock($m->medicine_id));
        }
    }

    public function test_other_forms_still_require_and_keep_the_chosen_quantity(): void
    {
        $m = $this->medicine('Oral Suspension', 'bottle');
        $this->submit([['medicine_id' => $m->medicine_id]])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->submit([['medicine_id' => $m->medicine_id, 'quantity' => 3]])->assertCreated()->assertJsonPath('items.0.quantity', 3);
    }

    public function test_mixed_request_saves_automatic_and_selected_quantities(): void
    {
        $tablet = $this->medicine();
        $bottle = $this->medicine('Syrup', 'bottle');
        $this->submit([['medicine_id' => $tablet->medicine_id], ['medicine_id' => $bottle->medicine_id, 'quantity' => 1]])
            ->assertCreated()->assertJsonPath('items.0.quantity', 10)->assertJsonPath('items.1.quantity', 1);
    }

    public function test_automatic_ten_is_blocked_when_free_stock_is_less_than_ten(): void
    {
        $m = $this->medicine('Capsule', 'capsule', 9);
        $this->submit([['medicine_id' => $m->medicine_id]])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->assertDatabaseCount('requests', 0);
        $this->assertSame(9, app(InventoryService::class)->availableStock($m->medicine_id));
    }

    public function test_reserved_stock_cannot_be_used_for_an_automatic_request(): void
    {
        $m = $this->medicine();
        $resident = Resident::create(['name' => 'Another Resident', 'address' => 'Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-009999']);
        $other = MedicineRequest::create(['resident_id' => $resident->resident_id,
            'request_type' => 'medicine', 'status' => 'approved', 'request_date' => now(), 'reviewed_at' => now()]);
        $other->items()->create(['medicine_id' => $m->medicine_id, 'quantity' => 15]);
        $this->submit([['medicine_id' => $m->medicine_id]])->assertUnprocessable();
        $this->assertSame(5, app(InventoryService::class)->freeStock($m->medicine_id));
    }

    public function test_pending_requests_can_be_updated_with_automatic_quantity_without_rewriting_other_records(): void
    {
        $m = $this->medicine();
        $res = $this->submit([['medicine_id' => $m->medicine_id, 'quantity' => 2]])->assertCreated();
        $id = $res->json('request_id');
        $this->getJson("/api/requests/{$id}", $this->headers)->assertOk()->assertJsonPath('items.0.quantity', 2);
        $this->putJson("/api/requests/{$id}", ['items' => [['medicine_id' => $m->medicine_id]]], $this->headers)
            ->assertOk()->assertJsonPath('items.0.quantity', 10);
    }

    public function test_restock_requests_have_no_quantity_even_for_tablets(): void
    {
        $m = $this->medicine('Tablet', 'tablet', 0);
        $this->submit([['medicine_id' => $m->medicine_id]], 'restock')->assertCreated()->assertJsonPath('items.0.quantity', null);
    }

    public function test_invalid_explicit_quantities_and_malformed_ids_are_not_replaced_by_the_preset(): void
    {
        $m = $this->medicine();
        foreach ([null, 0, -1, 1.5, 'bad', 11] as $quantity) {
            $this->submit([['medicine_id' => $m->medicine_id, 'quantity' => $quantity]])->assertUnprocessable();
        }
        foreach ([[1], '999999999999999999999999999999999', false, 999999] as $id) {
            $this->submit([['medicine_id' => $id]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('requests', 0);
    }
}
