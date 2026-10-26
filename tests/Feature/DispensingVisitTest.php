<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A resident claims an approved online request and asks for more medicine in person (same visit):
 * one dispensing record, each medicine marked as asked for in the request or as walk-in.
 */
class DispensingVisitTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken];
    }

    private function medicine(string $name, int $stock, bool $rx = false): Medicine
    {
        $m = Medicine::create(['medicine_name' => $name, 'category' => 'Vitamins', 'unit' => 'tablet', 'reorder_level' => 5, 'requires_prescription' => $rx]);
        Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => $stock, 'expiration_date' => now()->addYear()->toDateString()]);
        return $m;
    }

    public function test_request_and_in_person_medicines_are_one_dispensing_record(): void
    {
        $staff = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $carlo = Resident::create(['name' => 'Carlo Dalisay', 'address' => 'Zone I Poblacion, Bulan, Sorsogon', 'qr_code' => 'BBC-000005']);
        $ibu = $this->medicine('Ibuprofen 200mg Tablet', 50);
        $salbu = $this->medicine('Salbutamol 2mg Tablet', 50);
        $methyl = $this->medicine('Methyldopa 250mg', 50);

        $req = MedicineRequest::create(['resident_id' => $carlo->resident_id, 'request_type' => 'medicine', 'status' => 'approved',
            'request_date' => now()->subDay(), 'reviewed_by' => $staff->user_id, 'reviewed_at' => now()->subHour()]);
        RequestItem::create(['request_id' => $req->request_id, 'medicine_id' => $ibu->medicine_id, 'quantity' => 1]);
        RequestItem::create(['request_id' => $req->request_id, 'medicine_id' => $salbu->medicine_id, 'quantity' => 9]);
        $h = $this->headers($staff);

        // The limit per medicine counts the request and the medicine added in person together (9 + 2 > 10).
        $this->postJson('/api/dispensing', ['request_id' => $req->request_id, 'qr_code' => 'BBC-000005',
            'items' => [['medicine_id' => $salbu->medicine_id, 'quantity' => 2]]], $h)
            ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame(0, Dispensing::count());

        // Methyldopa asked for at the counter + 1 more Salbutamol: given with the request, in ONE record.
        $this->postJson('/api/dispensing', ['request_id' => $req->request_id, 'qr_code' => 'BBC-000005', 'items' => [
            ['medicine_id' => $methyl->medicine_id, 'quantity' => 10], ['medicine_id' => $salbu->medicine_id, 'quantity' => 1],
        ]], $h)->assertCreated();

        $this->assertSame(1, Dispensing::count());
        $this->assertSame(1, MedicineRequest::count()); // no separate walk-in request
        $d = Dispensing::with('items')->first();
        $this->assertSame($req->request_id, $d->request_id);
        $this->assertSame([
            [$ibu->medicine_id, 1, 'request'], [$salbu->medicine_id, 9, 'request'],
            [$methyl->medicine_id, 10, 'walk_in'], [$salbu->medicine_id, 1, 'walk_in'],
        ], $d->items->sortBy('dispensing_item_id')->map(fn ($i) => [$i->medicine_id, $i->quantity, $i->source])->values()->all());
        $this->assertSame('dispensed', $req->fresh()->status);
        $this->assertSame('Online request + Walk-in', $d->sourceLabel());
        // The originally requested medicines are kept as they were; the extra ones are marked walk-in.
        $this->assertSame(['request', 'request', 'walk_in', 'walk_in'], $req->items()->orderBy('request_item_id')->pluck('source')->all());

        // Stock: every medicine given is deducted once.
        $this->assertSame(40, (int) Inventory::where('medicine_id', $salbu->medicine_id)->sum('quantity'));
        $this->assertSame(40, (int) Inventory::where('medicine_id', $methyl->medicine_id)->sum('quantity'));

        // Dispensing Records and the report show one row, with Request / Walk-in per medicine.
        $this->getJson('/api/dispensing?per_page=5', $h)->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.items.2.source', 'walk_in')->assertJsonPath('summary.total_quantity', 21);
        $row = $this->getJson('/api/reports/dispensing', $this->headers($admin))->assertOk()->json('rows.0');
        $this->assertSame('Online request + Walk-in', $row['source']);
        $this->assertStringContainsString('Methyldopa 250mg x10 (walk-in)', $row['medicines']);
        $this->assertStringContainsString('Ibuprofen 200mg Tablet x1 (request)', $row['medicines']);
    }

    public function test_walk_in_release_and_plain_request_are_unchanged(): void
    {
        $staff = User::factory()->create();
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-000001']);
        $para = $this->medicine('Paracetamol 500mg', 30);
        $amox = $this->medicine('Amoxicillin 500mg Capsule', 30, true);
        $h = $this->headers($staff);

        // Walk-in only: its own request (remark Walk-in), every medicine walk-in.
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => 'BBC-000001', 'items' => [['medicine_id' => $para->medicine_id, 'quantity' => 3]]], $h)->assertCreated();
        $walk = Dispensing::with('items', 'request')->latest('dispensing_id')->first();
        $this->assertSame(['Walk-in', 'walk_in'], [$walk->sourceLabel(), $walk->items[0]->source]);

        // A request claimed without extra medicines: every medicine "request".
        $req = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'medicine', 'status' => 'approved',
            'request_date' => now(), 'reviewed_by' => $staff->user_id, 'reviewed_at' => now()]);
        RequestItem::create(['request_id' => $req->request_id, 'medicine_id' => $para->medicine_id, 'quantity' => 2]);
        // A prescription medicine added at the counter needs the prescription check.
        $this->postJson('/api/dispensing', ['request_id' => $req->request_id, 'qr_code' => 'BBC-000001',
            'items' => [['medicine_id' => $amox->medicine_id, 'quantity' => 2]]], $h)->assertStatus(422);
        $this->postJson('/api/dispensing', ['request_id' => $req->request_id, 'qr_code' => 'BBC-000001'], $h)->assertCreated();
        $plain = Dispensing::with('items', 'request')->latest('dispensing_id')->first();
        $this->assertSame(['Online request', 'request'], [$plain->sourceLabel(), $plain->items[0]->source]);
    }
}
