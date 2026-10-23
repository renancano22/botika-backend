<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Notification;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tables and reports carry the data the ERD (Fig. 4.6) defines, shown the same way everywhere. */
class TableDataTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_reports_and_sms_log_have_complete_data(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'Joy Mercado']);
        $headers = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        $syrup = Medicine::create(['medicine_name' => 'Multivitamin Syrup', 'category' => 'Vitamins', 'unit' => 'bottle', 'reorder_level' => 20, 'stock_verified_at' => now()]); // checked: none in stock
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);

        // A walk-in release and an online request.
        foreach ([['Walk-in', 4], [null, 6]] as [$remarks, $qty]) {
            $r = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'medicine', 'status' => 'dispensed', 'request_date' => now(), 'remarks' => $remarks]);
            RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $para->medicine_id, 'quantity' => $qty]);
            $d = Dispensing::create(['request_id' => $r->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => now()]);
            DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $para->medicine_id, 'quantity' => $qty]);
        }
        $restock = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'restock', 'status' => 'pending', 'request_date' => now()]);
        RequestItem::create(['request_id' => $restock->request_id, 'medicine_id' => $syrup->medicine_id, 'quantity' => null]);
        Notification::create(['resident_id' => $ana->resident_id, 'request_id' => $restock->request_id, 'type' => 'submitted', 'message' => 'Submitted', 'channel' => 'app', 'status' => 'sent', 'sent_at' => now()]);

        // Dispensing Records report: the request each release was for, and walk-in / online (like the Dispensing Records page).
        $rows = collect($this->getJson('/api/reports/dispensing', $headers)->assertOk()->json('rows'))->sortBy('dispensing_id')->values();
        $this->assertSame(['Walk-in', 'Online request'], $rows->pluck('source')->all());
        $this->assertSame('No. ' . Dispensing::min('request_id'), $rows[0]['request_no']);
        $this->assertSame('BBC-000001', $rows[0]['resident_id']);

        // Most Requested Medicines: the quantity has its unit; restock requests (no quantity) are counted, not added.
        $most = collect($this->getJson('/api/reports/most_requested', $headers)->assertOk()->json('rows'))->keyBy('medicine_name');
        $this->assertSame(['tablet', 10], [$most['Paracetamol 500mg']['unit'], (int) $most['Paracetamol 500mg']['total_quantity']]);
        $this->assertSame(['bottle', 0, 1], [$most['Multivitamin Syrup']['unit'], (int) $most['Multivitamin Syrup']['total_quantity'], (int) $most['Multivitamin Syrup']['restock_requests']]);

        // Medicine Shortages: the Medicine ID as shown everywhere (MED-0001), not the internal number.
        $short = collect($this->getJson('/api/reports/shortages', $headers)->assertOk()->json('rows'))->keyBy('medicine_name');
        $this->assertSame(Medicine::codeFor($syrup->medicine_id), $short['Multivitamin Syrup']['medicine_id']);
        $this->assertSame(1, $short['Multivitamin Syrup']['restock_requests']);

        // SMS log: the Resident ID (names can repeat) and the request the message was about.
        $this->getJson('/api/notifications?per_page=5', $headers)->assertOk()
            ->assertJsonPath('data.0.resident.qr_code', 'BBC-000001')->assertJsonPath('data.0.request_id', $restock->request_id);

        // User Accounts: resident accounts include the address (barangay).
        $user = User::create(['name' => 'Ana Cruz', 'email' => 'ana@gmail.com', 'password_hash' => 'Kalinga4821', 'role' => 'resident', 'is_active' => true]);
        $ana->update(['user_id' => $user->user_id]);
        $this->getJson('/api/users?per_page=50&role=resident', $headers)->assertOk()->assertJsonPath('data.0.resident.address', 'Gate, Bulan, Sorsogon');
    }
}
