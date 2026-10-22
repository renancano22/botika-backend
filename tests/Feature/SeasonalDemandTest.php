<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demand Forecasting → Seasonal Demand: medicines given out most in the rainy season (June–November) and the
 * dry season (December–May), and the expected demand per month and per season, from the dispensing records only.
 */
class SeasonalDemandTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    /** Releases $qty of $m to $resident on $date (a dispensed request and its dispensing record). */
    private function give(Resident $resident, Medicine $m, int $qty, string $date): void
    {
        $r = MedicineRequest::create(['resident_id' => $resident->resident_id, 'request_type' => 'medicine', 'status' => 'dispensed', 'request_date' => $date]);
        RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
        $d = Dispensing::create(['request_id' => $r->request_id, 'dispensed_at' => $date]);
        DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
    }

    private function season(array $data, string $key): array
    {
        return collect($data['seasons'])->firstWhere('key', $key);
    }

    public function test_seasonal_demand_comes_from_the_dispensing_records(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00')); // rainy season
        $admin = User::factory()->admin()->create();
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        $ben = Resident::create(['name' => 'Ben Lim', 'address' => 'Zone 1, Bulan, Sorsogon', 'contact_no' => '09170000002', 'qr_code' => 'BBC-000002']);
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 10]);
        $ors = Medicine::create(['medicine_name' => 'Oral Rehydration Salts', 'category' => 'Electrolytes', 'unit' => 'sachet', 'reorder_level' => 10]);
        $ceti = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);

        // Dry season (first record Feb 2026): Feb, Mar, Apr, May = 4 months.
        $this->give($ana, $para, 20, '2026-02-10 09:00:00');
        $this->give($ana, $ceti, 12, '2026-03-05 09:00:00');
        $this->give($ben, $para, 20, '2026-04-15 09:00:00');
        // Rainy season: Jun–Sep are complete (4 months); October is still in progress, so it is not counted.
        $this->give($ana, $para, 30, '2026-06-03 09:00:00');
        $this->give($ben, $para, 50, '2026-08-20 09:00:00');
        $this->give($ana, $ors, 16, '2026-07-07 09:00:00');
        $this->give($ben, $ors, 24, '2026-09-09 09:00:00');
        $this->give($ana, $para, 99, '2026-10-05 09:00:00'); // this month: not counted

        $data = $this->getJson('/api/forecasts/seasonal', $this->token($admin))->assertOk()
            ->assertJsonPath('current', 'rainy')->assertJsonPath('next', 'dry')->json();

        $rainy = $this->season($data, 'rainy');
        $this->assertTrue($rainy['is_current']);
        $this->assertSame(['2026-06-01', '2026-11-30'], [$rainy['starts'], $rainy['ends']]);
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09'], $rainy['months_used']);
        $this->assertTrue($rainy['provisional']); // fewer than 6 months

        // Most given out first: Paracetamol 80 / 4 = 20 a month, ORS 40 / 4 = 10 a month.
        [$p, $o] = $rainy['medicines'];
        $this->assertSame(['Paracetamol 500mg', 80, 20, 120, 2, 2], [$p['medicine_name'], $p['total'], (int) $p['avg_per_month'], $p['expected_season'], $p['times_dispensed'], $p['residents']]);
        // Paracetamol in the dry season: 40 / 4 = 10 a month → +100% in the rainy season.
        $this->assertEquals(10, $p['other_avg_per_month']);
        $this->assertSame(100, $p['change_percent']);
        // ORS was never given out in the dry season.
        $this->assertSame('Oral Rehydration Salts', $o['medicine_name']);
        $this->assertTrue($o['only_this_season']);
        $this->assertNull($o['change_percent']);

        $dry = $this->season($data, 'dry');
        $this->assertFalse($dry['is_current']);
        $this->assertSame(['2026-12-01', '2027-05-31'], [$dry['starts'], $dry['ends']]); // the next dry season
        $this->assertSame(['2026-02', '2026-03', '2026-04', '2026-05'], $dry['months_used']);
        $this->assertSame(['Paracetamol 500mg', 'Cetirizine 10mg'], array_column($dry['medicines'], 'medicine_name'));
        $this->assertSame(-50, $dry['medicines'][0]['change_percent']);
        $this->assertSame(18, $dry['medicines'][1]['expected_season']); // 12 / 4 × 6

        // Administrator only.
        $this->getJson('/api/forecasts/seasonal', $this->token(User::factory()->create()))->assertForbidden();
    }

    public function test_no_records_and_this_month_so_far(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $admin = User::factory()->admin()->create();

        // No dispensing records yet: nothing is made up.
        $data = $this->getJson('/api/forecasts/seasonal', $this->token($admin))->assertOk()->json();
        foreach ($data['seasons'] as $season) {
            $this->assertSame([], $season['months_used']);
            $this->assertSame([], $season['medicines']);
            $this->assertFalse($season['provisional']);
        }

        // The first records are from this month: used as a provisional basis for the current season only.
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 10]);
        $this->give($ana, $para, 7, '2026-10-05 09:00:00');
        $data = $this->getJson('/api/forecasts/seasonal', $this->token($admin))->assertOk()->json();
        $rainy = $this->season($data, 'rainy');
        $this->assertSame(['2026-10'], $rainy['months_used']);
        $this->assertTrue($rainy['partial']);
        $this->assertTrue($rainy['provisional']);
        $this->assertSame(7, $rainy['medicines'][0]['total']);
        $this->assertNull($rainy['medicines'][0]['other_avg_per_month']); // no dry season records yet
        $this->assertSame([], $this->season($data, 'dry')['months_used']);
    }

    public function test_december_is_in_the_dry_season(): void
    {
        $this->travelTo(Carbon::parse('2027-01-10 10:00:00'));
        $this->assertSame('dry', \App\Services\ForecastService::seasonOf(12));
        [$start, $end] = \App\Services\ForecastService::seasonRange(now());
        $this->assertSame(['2026-12-01', '2027-05-31'], [$start->toDateString(), $end->toDateString()]);
    }
}
