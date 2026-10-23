<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Forecast;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Demand Forecasting: "Generate Forecast" saves the forecast (D6) and the page shows when and by whom. */
class ForecastRunTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_generate_forecast_is_recorded_and_shown(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 14:40:00'));
        $admin = User::factory()->admin()->create(['name' => 'Admin One']);
        $h = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic/Antipyretic', 'unit' => 'tablet', 'reorder_level' => 10]);
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        foreach (['2026-08-10' => 20, '2026-09-10' => 30] as $date => $qty) {
            $r = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'medicine', 'status' => 'dispensed', 'request_date' => $date]);
            $d = Dispensing::create(['request_id' => $r->request_id, 'dispensed_at' => $date]);
            DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $med->medicine_id, 'quantity' => $qty]);
        }

        // Never generated yet: the page says so, and nothing is saved by just opening it.
        $this->getJson('/api/forecasts?months=12&horizon=3', $h)->assertOk()->assertJsonPath('last_generated', null);
        $this->assertSame(0, Forecast::count());

        // Generate Forecast: saved to D6, and recorded with the date, the admin, the method and the period.
        $this->postJson('/api/forecasts/generate', ['months' => 12, 'horizon' => 3, 'method' => 'moving_average'], $h)->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('last_generated', [
                'generated_at' => '2026-10-23 14:40:00', 'generated_by' => 'Admin One', 'method' => 'moving_average',
                'history_months' => 12, 'horizon' => 3, 'period_start' => '2026-11', 'period_end' => '2027-01', 'medicines' => 1,
            ]);
        $this->assertGreaterThan(0, Forecast::where('medicine_id', $med->medicine_id)->count());

        // Opening the page later shows the last generated forecast.
        $this->travel(2)->hours();
        $this->getJson('/api/forecasts', $h)->assertOk()
            ->assertJsonPath('last_generated.generated_at', '2026-10-23 14:40:00')->assertJsonPath('last_generated.generated_by', 'Admin One');
    }
}
