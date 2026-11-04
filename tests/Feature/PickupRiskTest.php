<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Approved requests whose set-aside medicine expires before pickup (or is no longer enough) are flagged for staff. */
class PickupRiskTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_set_aside_medicine_that_expires_before_pickup_is_flagged(): void
    {
        $staff = User::factory()->create();
        $h = ['Authorization' => 'Bearer ' . $staff->createToken('t')->plainTextToken];
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'qr_code' => 'BBC-000001']);
        $drops = Medicine::create(['medicine_name' => 'Ascorbic Acid 100mg Oral Drops', 'category' => 'Vitamins', 'unit' => 'bottle', 'reorder_level' => 5]);
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $soon = now()->addDays(3)->toDateString();
        Inventory::create(['medicine_id' => $drops->medicine_id, 'quantity' => 10, 'expiration_date' => $soon]);         // the only stock: expires in 3 days
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 10, 'expiration_date' => $soon]);          // expires in 3 days, but…
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]); // …more stock lasts

        $approve = function (Medicine $m) use ($ana, $staff) {
            $r = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'medicine', 'status' => 'approved',
                'request_date' => now(), 'reviewed_by' => $staff->user_id, 'reviewed_at' => now()]);
            RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $m->medicine_id, 'quantity' => 5]);
            return $r;
        };
        $atRisk = $approve($drops);  // pickup deadline in 7 days, the drops expire in 3
        $safe = $approve($para);

        // Stock alerts, the Requests list and Dispense Medicine all show it (only for the request at risk).
        $alerts = $this->getJson('/api/inventory/alerts', $h)->assertOk()->json('pickup_risks');
        $this->assertCount(1, $alerts);
        $this->assertSame([$atRisk->request_id, 'expires_before_pickup', $soon, 'Ana Cruz'],
            [$alerts[0]['request_id'], $alerts[0]['reason'], $alerts[0]['expires_on'], $alerts[0]['resident']]);
        $rows = collect($this->getJson('/api/requests?per_page=10&status=approved', $h)->json('data'))->keyBy('request_id');
        $this->assertSame('expires_before_pickup', $rows[$atRisk->request_id]['stock_warnings'][0]['reason']);
        $this->assertNull($rows[$safe->request_id]['stock_warnings']);
        $lookup = collect($this->getJson('/api/residents/lookup?code=BBC-000001', $h)->json('approved_requests'))->keyBy('request_id');
        $this->assertSame('expires_before_pickup', $lookup[$atRisk->request_id]['stock_warnings'][0]['reason']);

        // After the drops expire, the set-aside stock is no longer there at all.
        $this->travel(4)->days();
        $h = ['Authorization' => 'Bearer ' . $staff->createToken('t2')->plainTextToken]; // a new log-in (sessions end when idle)
        $after = $this->getJson('/api/inventory/alerts', $h)->assertOk()->json('pickup_risks');
        $this->assertSame(['not_enough', 0, 5], [$after[0]['reason'], $after[0]['unexpired_stock'], $after[0]['set_aside']]);
        $this->assertCount(1, $after); // Paracetamol is still covered by the batch that lasts

        // Residents never get these warnings in their own list.
        $user = User::create(['name' => 'Ana Cruz', 'email' => 'ana@gmail.com', 'password_hash' => 'Kalinga4821', 'role' => 'resident', 'is_active' => true]);
        $ana->update(['user_id' => $user->user_id]);
        $mine = $this->getJson('/api/requests?per_page=10', ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken])->json('data');
        $this->assertNull($mine[0]['stock_warnings'] ?? null);
    }
}
