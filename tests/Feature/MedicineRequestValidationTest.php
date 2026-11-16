<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicine;
    private array $headers;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jpa0AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $reg = $this->postJson('/api/register', ['name' => 'Ana Dela Peña', 'barangay' => 'Gate', 'contact_no' => '09171234567',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'])->assertCreated();
        $this->headers = ['Authorization' => 'Bearer ' . $reg->json('token')];
        $this->medicine = Medicine::create(['medicine_name' => 'Paracetamol', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $this->medicine->medicine_id, 'quantity' => 5, 'expiration_date' => now()->addYear()->toDateString()]);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['request_type' => 'medicine', 'items' => [['medicine_id' => $this->medicine->medicine_id, 'quantity' => 2]]], $changes);
    }

    public function test_quantities_and_stock_are_checked_without_creating_a_request(): void
    {
        foreach ([null, 0, -1, 1.5, 'bad', 11, 6] as $quantity) {
            $this->postJson('/api/requests', $this->payload(['items' => [['medicine_id' => $this->medicine->medicine_id, 'quantity' => $quantity]]]), $this->headers)
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        }
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_list_shape_duplicates_and_medicine_ids_are_validated(): void
    {
        foreach ([[], ['bad'], ['first' => ['medicine_id' => 1, 'quantity' => 1]], array_fill(0, 6, ['medicine_id' => 1, 'quantity' => 1]),
            [['medicine_id' => $this->medicine->medicine_id, 'quantity' => 1], ['medicine_id' => $this->medicine->medicine_id, 'quantity' => 1]],
            [['medicine_id' => [1], 'quantity' => 1]], [['medicine_id' => 999999, 'quantity' => 1]]] as $items) {
            $this->postJson('/api/requests', $this->payload(['items' => $items]), $this->headers)->assertUnprocessable();
        }
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_notes_are_validated_and_valid_multiline_notes_are_kept(): void
    {
        foreach ([str_repeat('x', 501), ['bad'], "reason\0text"] as $note) {
            $this->postJson('/api/requests', $this->payload(['note' => $note]), $this->headers)->assertUnprocessable()->assertJsonValidationErrors('note');
        }
        $this->postJson('/api/requests', $this->payload(['note' => "As advised\nby my doctor."]), $this->headers)->assertCreated();
        $this->assertDatabaseHas('requests', ['resident_note' => "As advised\nby my doctor."]);
    }

    public function test_five_distinct_medicines_are_allowed_but_six_are_refused(): void
    {
        $items = [['medicine_id' => $this->medicine->medicine_id, 'quantity' => 1]];
        for ($i = 2; $i <= 6; $i++) {
            $medicine = Medicine::create(['medicine_name' => "Medicine {$i}", 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
            Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 5, 'expiration_date' => now()->addYear()->toDateString()]);
            $items[] = ['medicine_id' => $medicine->medicine_id, 'quantity' => 1];
        }
        $this->postJson('/api/requests', $this->payload(['items' => $items]), $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.items.0', 'One request can have at most 5 medicines.');
        $this->assertDatabaseCount('requests', 0);
        $this->postJson('/api/requests', $this->payload(['items' => array_slice($items, 0, 5)]), $this->headers)
            ->assertCreated()->assertJsonCount(5, 'items');
    }

    public function test_prescription_data_types_encoding_content_and_size_are_validated(): void
    {
        $this->medicine->update(['requires_prescription' => true]);
        $cases = [null, ['name' => 'x.png', 'data' => ['bad']], ['name' => ['bad'], 'data' => 'x'],
            ['name' => '', 'data' => 'x'], ['name' => str_repeat('x', 201), 'data' => 'x'],
            ['name' => 'x.png', 'data' => 'data:image/png;base64,===='],
            ['name' => 'x.png', 'data' => 'data:image/png;base64,' . base64_encode('not an image')],
            ['name' => 'x.pdf', 'data' => 'data:application/pdf;base64,' . self::PNG],
            ['name' => 'x.jpg', 'data' => 'data:image/jpeg;base64,' . self::PNG],
            ['name' => 'x.png', 'data' => 'data:image/png;base64,' . base64_encode(str_repeat('x', 4 * 1024 * 1024 + 1))]];
        foreach ($cases as $prescription) {
            $this->postJson('/api/requests', $this->payload(['prescription' => $prescription]), $this->headers)->assertUnprocessable()->assertJsonValidationErrors('prescription');
        }
        $this->assertDatabaseCount('requests', 0);
        $this->assertDatabaseCount('prescriptions', 0);
        $this->postJson('/api/requests', $this->payload(['prescription' => ['name' => 'rx.png', 'data' => 'data:image/png;base64,' . self::PNG]]), $this->headers)->assertCreated();
        $this->assertDatabaseHas('prescriptions', ['mime_type' => 'image/png', 'size' => strlen(base64_decode(self::PNG))]);
    }

    public function test_duplicate_open_requests_are_refused_and_medicines_cannot_be_swapped_on_edit(): void
    {
        $id = $this->postJson('/api/requests', $this->payload(), $this->headers)->assertCreated()->json('request_id');
        $this->postJson('/api/requests', $this->payload(), $this->headers)->assertUnprocessable()->assertJsonValidationErrors('items');
        $other = Medicine::create(['medicine_name' => 'Amoxicillin', 'category' => 'Antibiotic', 'unit' => 'capsule', 'requires_prescription' => true]);
        $this->putJson("/api/requests/{$id}", ['items' => [['medicine_id' => $other->medicine_id, 'quantity' => 1]]], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson("/api/requests/{$id}", ['items' => [['medicine_id' => $this->medicine->medicine_id, 'quantity' => 3]]], $this->headers)->assertOk();
        $this->assertDatabaseCount('requests', 1);
    }
}
