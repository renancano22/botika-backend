<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
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
 * Dashboard: every number must match the database records (counting rules in DashboardController),
 * follow the date filter, update when records are added, work with no records, and respect permissions.
 */
class DashboardTest extends TestCase
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

    /** @return array<string, Medicine> */
    private function records(): array
    {
        $a = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 10]);
        $b = Medicine::create(['medicine_name' => 'Amoxicillin 500mg', 'category' => 'Antibiotic', 'unit' => 'capsule', 'reorder_level' => 10]);
        $c = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 10]);
        Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 10]); // stock never recorded
        Inventory::create(['medicine_id' => $a->medicine_id, 'quantity' => 100, 'expiration_date' => '2027-06-01']); // available
        Inventory::create(['medicine_id' => $b->medicine_id, 'quantity' => 5, 'expiration_date' => '2026-10-31']);  // low stock, expiring within 1 month
        Inventory::create(['medicine_id' => $c->medicine_id, 'quantity' => 8, 'expiration_date' => '2026-10-01']);  // expired → out of stock

        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        $ben = Resident::create(['name' => 'Ben Ramos', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000002', 'qr_code' => 'BBC-000002']);
        $carla = Resident::create(['name' => 'Carla Diaz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => null, 'qr_code' => 'BBC-000003']);
        $staff = User::factory()->create();

        $req = function (Resident $r, string $type, string $date, string $status, array $items, ?string $remarks = null) use ($staff) {
            $q = MedicineRequest::create(['resident_id' => $r->resident_id, 'request_type' => $type, 'request_date' => $date, 'status' => $status, 'remarks' => $remarks,
                'reviewed_by' => $status === 'pending' ? null : $staff->user_id, 'reviewed_at' => $status === 'approved' ? now()->subDay() : ($status === 'pending' ? null : $date)]);
            foreach ($items as [$m, $qty]) RequestItem::create(['request_id' => $q->request_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
            return $q;
        };
        $give = function (MedicineRequest $q, string $at, array $items) use ($staff) {
            $d = Dispensing::create(['request_id' => $q->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => $at]);
            foreach ($items as [$m, $qty]) DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
        };

        // August (older history) and October.
        $give($req($ana, 'medicine', '2026-08-15 09:00:00', 'dispensed', [[$a, 4]]), '2026-08-15 10:00:00', [[$a, 4]]);
        $give($req($ana, 'medicine', '2026-10-01 09:00:00', 'dispensed', [[$a, 10], [$b, 2]]), '2026-10-02 09:00:00', [[$a, 10], [$b, 2]]); // 2 medicines, 1 request
        $req($ben, 'medicine', '2026-10-05 09:00:00', 'approved', [[$a, 5]]);
        $req($ana, 'medicine', '2026-10-10 09:00:00', 'pending', [[$a, 3]]);
        $req($ben, 'medicine', '2026-10-12 09:00:00', 'rejected', [[$b, 4]]);
        $req($ana, 'medicine', '2026-10-15 09:00:00', 'cancelled', [[$a, 7]]);
        $req($ben, 'medicine', '2026-10-03 09:00:00', 'expired', [[$a, 6]]);
        $give($req($carla, 'medicine', '2026-10-20 15:00:00', 'dispensed', [[$a, 1]], 'Walk-in'), '2026-10-20 15:00:00', [[$a, 1]]);
        $req($ana, 'restock', '2026-10-08 09:00:00', 'pending', [[$c, 1]]);
        $req($ben, 'restock', '2026-10-09 09:00:00', 'cancelled', [[$c, 1]]);
        $req($ben, 'restock', '2026-10-11 09:00:00', 'approved', [[$c, 2]]);

        return compact('a', 'b', 'c');
    }

    public function test_dashboard_numbers_match_the_database_records(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->records();
        $admin = User::factory()->admin()->create();
        $before = [MedicineRequest::count(), Dispensing::count(), DispensingItem::sum('quantity')];

        $res = $this->getJson('/api/dashboard?from=2026-10-01&to=2026-10-21', $this->token($admin))->assertOk();

        // As of now: each medicine counted once by the quantity of its non-expired batches; the statuses add up to the total.
        $res->assertJsonPath('counts.medicines', 4)->assertJsonPath('counts.available', 1)->assertJsonPath('counts.low_stock', 1)
            ->assertJsonPath('counts.out_of_stock', 1)->assertJsonPath('counts.unrecorded', 1)
            ->assertJsonPath('counts.near_expiry', 1)->assertJsonPath('counts.expired', 1)
            ->assertJsonPath('counts.pending_requests', 1)->assertJsonPath('counts.approved_to_dispense', 1)
            ->assertJsonPath('counts.pending_restock', 1)->assertJsonPath('counts.dispensed_today', 0)
            ->assertJsonPath('counts.residents', 3);
        $c = $res->json('counts');
        $this->assertSame($c['medicines'], $c['available'] + $c['low_stock'] + $c['out_of_stock'] + $c['unrecorded']);

        // Requests in the period: each request once (request No. 2 has 2 medicines), with its status.
        $res->assertJsonPath('analytics.period', ['from' => '2026-10-01', 'to' => '2026-10-21', 'group' => 'day'])
            ->assertJsonPath('analytics.records_since', '2026-08-15')
            ->assertJsonPath('analytics.requests.total', 7)
            ->assertJsonPath('analytics.requests.walk_in', 1)
            ->assertJsonPath('analytics.requests.by_status', ['pending' => 1, 'approved' => 1, 'dispensed' => 2, 'rejected' => 1, 'cancelled' => 1, 'expired' => 1])
            ->assertJsonPath('analytics.requests.demand', 4)               // pending + approved + dispensed
            ->assertJsonPath('analytics.requests.quantity_requested', 21)  // 10 + 2 + 5 + 3 + 1 (not the rejected, cancelled or expired ones)
            ->assertJsonPath('analytics.requests.restock', ['total' => 2, 'pending' => 1]) // the cancelled one is not counted
            ->assertJsonPath('analytics.dispensing', ['releases' => 2, 'quantity' => 13, 'residents' => 2]);

        // The trend has every day of the period and adds up to the same totals.
        $trend = collect($res->json('analytics.trend'));
        $this->assertCount(21, $trend);
        $this->assertSame(['2026-10-01', '2026-10-21'], [$trend->first()['period'], $trend->last()['period']]);
        $this->assertSame(7, $trend->sum('requests'));
        $this->assertSame(2, $trend->sum('releases'));
        $this->assertSame(13, $trend->sum('quantity'));
        $this->assertSame(['period' => '2026-10-02', 'requests' => 0, 'releases' => 1, 'quantity' => 12], $trend->firstWhere('period', '2026-10-02'));

        // Most requested medicines: requests that count as demand, quantities requested and dispensed, restock requests, current stock.
        $top = $res->json('analytics.top_medicines');
        $this->assertSame(['Paracetamol 500mg', 'Amoxicillin 500mg', 'Losartan 50mg'], array_column($top, 'medicine_name'));
        $this->assertSame(['requests' => 4, 'quantity_requested' => 19, 'quantity_dispensed' => 11, 'restock_requests' => 0, 'available_stock' => 100, 'status' => 'available'],
            array_intersect_key($top[0], array_flip(['requests', 'quantity_requested', 'quantity_dispensed', 'restock_requests', 'available_stock', 'status'])));
        $this->assertSame([1, 2, 2, 'low_stock'], [$top[1]['requests'], $top[1]['quantity_requested'], $top[1]['quantity_dispensed'], $top[1]['status']]);
        $this->assertSame([0, 2, 'out_of_stock'], [$top[2]['requests'], $top[2]['restock_requests'], $top[2]['status']]);

        // Same rule as Demand Forecasting's "most requested medicines".
        $forecast = app(\App\Services\ForecastService::class)->topRequestedMedicines(Carbon::parse('2026-10-01'));
        $this->assertSame([4, 1], array_column($forecast, 'total'));

        // Default period: the last 6 months (May to October), grouped by month, including the August record.
        $default = $this->getJson('/api/dashboard', $this->token($admin))->assertOk()
            ->assertJsonPath('analytics.period', ['from' => '2026-05-01', 'to' => '2026-10-21', 'group' => 'month'])
            ->assertJsonPath('analytics.requests.total', 8)
            ->assertJsonPath('analytics.dispensing.quantity', 17);
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_column($default->json('analytics.trend'), 'period'));
        $this->assertSame(['period' => '2026-08', 'requests' => 1, 'releases' => 1, 'quantity' => 4], $default->json('analytics.trend.3'));

        // A period with only older history, and a period with no records at all.
        $this->getJson('/api/dashboard?from=2026-08-01&to=2026-08-31', $this->token($admin))
            ->assertJsonPath('analytics.requests.total', 1)->assertJsonPath('analytics.top_medicines.0.quantity_dispensed', 4);
        $this->getJson('/api/dashboard?from=2026-09-01&to=2026-09-30', $this->token($admin))
            ->assertJsonPath('analytics.requests.total', 0)->assertJsonPath('analytics.dispensing.releases', 0)
            ->assertJsonPath('analytics.top_medicines', []);

        // A wrong date range is refused; reading the dashboard never changes the records.
        $this->getJson('/api/dashboard?from=2026-10-21&to=2026-10-01', $this->token($admin))->assertStatus(422)->assertJsonValidationErrors('to');
        $this->assertSame($before, [MedicineRequest::count(), Dispensing::count(), DispensingItem::sum('quantity')]);
    }

    public function test_dashboard_with_no_records_and_after_new_records_are_added(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        // No records yet: zeros, empty lists, no error.
        $empty = $this->getJson('/api/dashboard', $this->token($admin))->assertOk()
            ->assertJsonPath('counts.medicines', 0)->assertJsonPath('counts.pending_requests', 0)
            ->assertJsonPath('analytics.records_since', null)->assertJsonPath('analytics.requests.total', 0)
            ->assertJsonPath('analytics.dispensing', ['releases' => 0, 'quantity' => 0, 'residents' => 0])
            ->assertJsonPath('analytics.top_medicines', []);
        $this->assertSame(0, collect($empty->json('analytics.trend'))->sum('requests'));

        // A resident submits a request, staff approve and dispense it: the dashboard follows each step.
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $med->medicine_id, 'quantity' => 50, 'expiration_date' => '2027-06-01']);
        $reg = $this->postJson('/api/register', ['name' => 'Maria Santos', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated();
        $id = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $med->medicine_id, 'quantity' => 10]]],
            ['Authorization' => 'Bearer ' . $reg->json('token')])->assertCreated()->json('request_id');

        $this->getJson('/api/dashboard', $this->token($staff))->assertOk()
            ->assertJsonPath('counts.pending_requests', 1)
            ->assertJsonPath('analytics.requests.total', 1)->assertJsonPath('analytics.requests.by_status.pending', 1)
            ->assertJsonPath('analytics.requests.quantity_requested', 10)
            ->assertJsonPath('analytics.top_medicines.0.requests', 1);

        $this->postJson("/api/requests/{$id}/approve", [], $this->token($staff))->assertOk();
        $this->postJson('/api/dispensing', ['request_id' => $id, 'qr_code' => $reg->json('user.resident.qr_code')], $this->token($staff))->assertCreated();

        // The same request is still counted once, now as dispensed; the stock went down by the quantity given.
        $this->getJson('/api/dashboard', $this->token($staff))->assertOk()
            ->assertJsonPath('counts.pending_requests', 0)->assertJsonPath('counts.dispensed_today', 1)
            ->assertJsonPath('analytics.requests.total', 1)->assertJsonPath('analytics.requests.by_status.dispensed', 1)
            ->assertJsonPath('analytics.dispensing', ['releases' => 1, 'quantity' => 10, 'residents' => 1])
            ->assertJsonPath('analytics.top_medicines.0.quantity_dispensed', 10)
            ->assertJsonPath('analytics.top_medicines.0.available_stock', 40);
    }

    public function test_dashboard_follows_user_permissions(): void
    {
        $this->records();
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        // Staff without access to requests: no request numbers, lists or request analysis.
        $this->putJson('/api/permissions/staff', ['permissions' => ['requests.view' => false]], $this->token($admin))->assertOk();
        $res = $this->getJson('/api/dashboard', $this->token($staff))->assertOk()
            ->assertJsonPath('sections', ['requests' => false, 'stock' => true, 'dispensing' => true])
            ->assertJsonMissingPath('recent_requests')->assertJsonMissingPath('ready_for_pickup')->assertJsonMissingPath('counts.pending_requests')
            ->assertJsonPath('analytics.requests', null)
            ->assertJsonPath('analytics.dispensing.releases', 3) // Aug 15, Oct 2 and Oct 20 (default: last 6 months)
            ->assertJsonPath('analytics.top_medicines.0.requests', 0); // dispensed quantities only
        $this->assertNotNull($res->json('counts.medicines'));

        // Without dispensing records either: no demand analysis at all.
        $this->putJson('/api/permissions/staff', ['permissions' => ['dispensing.records' => false]], $this->token($admin))->assertOk();
        $this->getJson('/api/dashboard', $this->token($staff))->assertOk()->assertJsonMissingPath('analytics')->assertJsonMissingPath('counts.dispensed_today');

        // The administrator always sees everything; residents get their own dashboard only.
        $this->getJson('/api/dashboard', $this->token($admin))->assertJsonPath('sections', ['requests' => true, 'stock' => true, 'dispensing' => true]);
        $resident = $this->postJson('/api/register', ['name' => 'Lito Reyes', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09179998888'])->assertCreated();
        $this->getJson('/api/dashboard', ['Authorization' => 'Bearer ' . $resident->json('token')])->assertOk()
            ->assertJsonMissingPath('analytics')->assertJsonMissingPath('counts.medicines')->assertJsonPath('counts.pending', 0);
    }
}
