<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Forecast;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demand Forecasting uses only the records in the database: the monthly history is the dispensed quantity
 * (D5 Dispensing), and the request records (D4) that are still open — pending, approved (set aside) and
 * waiting restock requests — are added to the restock recommendation.
 */
class ForecastAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_forecast_and_restock_come_from_the_dispensing_and_request_records(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 10]);
        $multi = Medicine::create(['medicine_name' => 'Multivitamin Syrup', 'category' => 'Vitamins', 'unit' => 'bottle', 'reorder_level' => 20, 'stock_verified_at' => now()]);
        $ceti = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 40, 'expiration_date' => '2027-06-01']);
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 30, 'expiration_date' => '2026-09-30']); // expired: not counted
        Inventory::create(['medicine_id' => $ceti->medicine_id, 'quantity' => 100, 'expiration_date' => '2027-06-01']);
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);

        $request = function (Medicine $m, string $type, string $status, int $qty, string $date) use ($ana, $staff) {
            $r = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => $type, 'status' => $status, 'request_date' => $date,
                'reviewed_by' => $status === 'pending' ? null : $staff->user_id, 'reviewed_at' => $status === 'approved' ? now()->subDay() : ($status === 'pending' ? null : $date)]);
            RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
            return $r;
        };
        $give = function (int $qty, string $date) use ($request, $para, $staff) {
            $r = $request($para, 'medicine', 'dispensed', $qty, $date);
            $d = Dispensing::create(['request_id' => $r->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => $date]);
            DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $para->medicine_id, 'quantity' => $qty]);
        };

        // Paracetamol given out: May 10, Jun 20, Jul 30, Aug 40, Sep 50 (two releases), and 5 so far in October.
        foreach (['2026-05-12' => 10, '2026-06-03' => 20, '2026-07-20' => 30, '2026-08-08' => 40, '2026-09-02' => 20, '2026-09-25' => 30, '2026-10-05' => 5] as $date => $qty) {
            $give($qty, "{$date} 09:00:00");
        }
        // Open requests (as of now): 8 set aside for an approved request, 6 in a pending request.
        $request($para, 'medicine', 'approved', 8, '2026-10-19 09:00:00');
        $request($para, 'medicine', 'pending', 6, '2026-10-20 09:00:00');
        // Multivitamin is out of stock: residents asked for it with restock requests. Only the waiting ones count.
        $request($multi, 'restock', 'pending', 10, '2026-10-10 09:00:00');
        $request($multi, 'restock', 'approved', 20, '2026-10-12 09:00:00');
        $request($multi, 'restock', 'cancelled', 5, '2026-10-13 09:00:00');
        $request($multi, 'restock', 'rejected', 7, '2026-10-14 09:00:00');
        $before = [MedicineRequest::count(), Dispensing::count(), DispensingItem::sum('quantity')];

        $res = $this->getJson('/api/forecasts?months=6&horizon=3&method=moving_average', $this->token($admin))->assertOk()
            ->assertJsonPath('history_keys', ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
            ->assertJsonPath('basis.months', ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09']) // complete months only
            ->assertJsonPath('forecast_keys', ['2026-11', '2026-12', '2027-01'])
            ->assertJsonPath('first_record_date', '2026-05-12')
            ->assertJsonPath('medicines_with_records', 1);
        $rows = collect($res->json('results'))->keyBy('medicine_name');
        $p = $rows['Paracetamol 500mg'];

        // The history is exactly the dispensed quantity per month in the database.
        $fromDb = DispensingItem::join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->where('dispensing_items.medicine_id', $para->medicine_id)->get(['dispensing.dispensed_at', 'dispensing_items.quantity'])
            ->groupBy(fn ($r) => substr((string) $r->dispensed_at, 0, 7))->map(fn ($g) => (int) $g->sum('quantity'))->all();
        $this->assertSame(['2026-05' => 10, '2026-06' => 20, '2026-07' => 30, '2026-08' => 40, '2026-09' => 50, '2026-10' => 5], $p['history']);
        $this->assertSame($fromDb, $p['history']);

        // Each technique on the complete months 10, 20, 30, 40, 50.
        $this->assertEqualsWithDelta(120, $p['methods']['moving_average']['predicted_demand'], 0.01);       // (30+40+50)/3 = 40 a month
        $this->assertEqualsWithDelta(210, $p['methods']['linear_regression']['predicted_demand'], 0.01);    // trend: 60, 70, 80
        $this->assertSame([60.0, 70.0, 80.0], array_map('floatval', $p['methods']['linear_regression']['monthly']));
        $this->assertEqualsWithDelta(20, $p['methods']['moving_average']['mae'], 0.01);                     // back-test: |40−20|, |50−30|

        // Stock: 40 not expired (the expired batch is not counted), 8 set aside → 32 free.
        $this->assertSame([40, 8, 32, 6, 0, 6], [$p['available_stock'], $p['reserved_stock'], $p['free_stock'], $p['pending_requests'], $p['restock_requests'], $p['waiting_demand']]);
        // Suggested restock = predicted 120 + waiting 6 + reorder level 10 − free stock 32.
        $this->assertSame(120, $p['predicted_demand']);
        $this->assertSame(104, $p['recommended_restock']);
        $this->assertSame('high', $p['priority']);

        // Out of stock with residents waiting: nothing was dispensed; the 2 waiting restock requests (no quantity) are
        // counted and make it a high priority (the cancelled and rejected ones are not counted).
        $m = $rows['Multivitamin Syrup'];
        $this->assertSame([0, 2, 0, 0], [$m['predicted_demand'], $m['restock_requests'], $m['waiting_demand'], $m['free_stock']]);
        $this->assertSame(20, $m['recommended_restock']); // 0 + 0 + reorder level 20 − 0
        $this->assertSame('high', $m['priority']);

        // A medicine with no records and enough stock needs nothing.
        $this->assertSame([0, 0, null], [$rows['Cetirizine 10mg']['predicted_demand'], $rows['Cetirizine 10mg']['recommended_restock'], $rows['Cetirizine 10mg']['priority']]);

        // The average error only counts the medicine that has records (not 20 ÷ 3 medicines).
        $this->assertEqualsWithDelta(20, $res->json('method_error.moving_average'), 0.01);
        $this->assertSame($p['methods']['linear_regression']['mae'], $res->json('method_error.linear_regression'));
        $res->assertJsonPath('summary.potential_stockouts', 2)->assertJsonPath('summary.restock_recommendations', 2);

        // Generate Forecast saves the same numbers to the forecast table (D6); records are never changed.
        $this->postJson('/api/forecasts/generate', ['months' => 6, 'horizon' => 3, 'method' => 'moving_average'], $this->token($admin))->assertOk();
        $this->assertEqualsWithDelta(40, Forecast::where('medicine_id', $para->medicine_id)->where('method', 'moving_average')->where('forecast_date', '2026-11-01')->value('predicted_demand'), 0.01);
        $this->assertEqualsWithDelta(80, Forecast::where('medicine_id', $para->medicine_id)->where('method', 'linear_regression')->where('forecast_date', '2027-01-01')->value('predicted_demand'), 0.01);
        $this->assertSame($before, [MedicineRequest::count(), Dispensing::count(), DispensingItem::sum('quantity')]);

        // A new record changes the forecast: 10 more given out in September → (30+40+60)/3 a month.
        $give(10, '2026-09-28 09:00:00');
        $p2 = collect($this->getJson('/api/forecasts?months=6&horizon=3&method=moving_average', $this->token($admin))->json('results'))->firstWhere('medicine_name', 'Paracetamol 500mg');
        $this->assertSame(60, $p2['history']['2026-09']);
        $this->assertSame(130, $p2['predicted_demand']);

        // When the approved request is dispensed, it moves from "set aside" into the history (counted once).
        $approved = MedicineRequest::where('status', 'approved')->firstOrFail();
        $approved->update(['status' => 'dispensed']);
        $d = Dispensing::create(['request_id' => $approved->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => now()]);
        DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $para->medicine_id, 'quantity' => 8]);
        Inventory::where('medicine_id', $para->medicine_id)->where('quantity', 40)->update(['quantity' => 32]);
        $p3 = collect($this->getJson('/api/forecasts?months=6&horizon=3&method=moving_average', $this->token($admin))->json('results'))->firstWhere('medicine_name', 'Paracetamol 500mg');
        $this->assertSame([32, 0, 32, 13], [$p3['available_stock'], $p3['reserved_stock'], $p3['free_stock'], $p3['history']['2026-10']]);
        $this->assertSame($p2['recommended_restock'], $p3['recommended_restock']); // same need, not counted twice
    }
}
