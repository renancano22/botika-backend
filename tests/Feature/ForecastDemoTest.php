<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Notification;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastDemoTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function headersFor(string $role = 'admin'): array
    {
        $user = User::factory()->create(['role' => $role]);
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function operationalSnapshot(): array
    {
        return collect([Medicine::class, Resident::class, MedicineRequest::class, Dispensing::class,
            DispensingItem::class, Inventory::class, Forecast::class, ForecastRun::class, Notification::class])
            ->mapWithKeys(fn ($class) => [$class => $class::all()->toArray()])->all();
    }

    public function test_september_demo_uses_real_algorithms_without_writes_or_sms(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        Http::fake();
        $headers = $this->headersFor();
        $medicine = Medicine::create(['medicine_name' => 'Real medicine', 'category' => 'Vitamins', 'unit' => 'tablet', 'reorder_level' => 10]);
        Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 123, 'expiration_date' => '2027-12-01']);
        $before = $this->operationalSnapshot();

        $response = $this->getJson('/api/forecasts?demo=1&months=6&horizon=3&method=moving_average', $headers)
            ->assertOk()->assertJsonPath('demo', true)->assertJsonPath('last_generated', null)
            ->assertJsonPath('first_record_date', '2026-09-01')
            ->assertJsonPath('basis.months', ['2026-09', '2026-10'])
            ->assertJsonPath('basis.partial', false)->assertJsonPath('medicines_with_records', 3)
            ->assertJsonPath('methods_info.linear_regression.available', false)
            ->assertJsonPath('method_error.moving_average', null)
            ->assertJsonPath('top_barangays', [])->assertJsonPath('top_medicines', []);
        $row = $response->json('results.0');
        $this->assertSame(-1, $row['medicine_id']);
        $this->assertSame(60, $row['history']['2026-09']);
        $this->assertSame(90, $row['history']['2026-10']);
        $this->assertSame(68, $row['history']['2026-11']);
        $this->assertEquals([75, 75, 75], $row['methods']['moving_average']['monthly']);
        $this->assertEquals([69, 69, 69], $row['methods']['exponential_smoothing']['monthly']);
        $this->assertSame(225, $row['predicted_demand']);
        $this->assertSame(190, $row['recommended_restock']); // 225 + reorder 10 - sample stock 45
        $this->assertNull($row['methods']['linear_regression']);

        $this->postJson('/api/forecasts/generate', ['demo' => true], $headers)->assertStatus(422);
        $this->assertSame($before, $this->operationalSnapshot());
        Http::assertNothingSent();
        $this->getJson('/api/forecasts?demo=0', $headers)->assertOk()->assertJsonPath('demo', false)
            ->assertJsonPath('summary.medicines_analyzed', 1)->assertJsonPath('first_record_date', null)
            ->assertJsonPath('results.0.medicine_name', 'Real medicine')->assertJsonPath('results.0.available_stock', 123);
    }

    public function test_demo_supports_trend_and_backtesting_only_after_enough_complete_months(): void
    {
        $this->travelTo(Carbon::parse('2027-01-17 12:00:00'));
        $response = $this->getJson('/api/forecasts?demo=1&method=linear_regression&horizon=3', $this->headersFor())
            ->assertOk()->assertJsonPath('first_record_date', '2026-09-01')
            ->assertJsonPath('basis.months', ['2026-09', '2026-10', '2026-11', '2026-12'])
            ->assertJsonPath('methods_info.linear_regression.available', true)
            ->assertJsonPath('methods_info.linear_regression.provisional', true);
        $this->assertEquals([180, 210, 240], $response->json('results.0.methods.linear_regression.monthly'));
        $this->assertEquals(0, $response->json('results.0.methods.linear_regression.mae'));
    }

    public function test_demo_is_admin_only_and_validates_inputs(): void
    {
        $this->getJson('/api/forecasts?demo=1')->assertUnauthorized();
        $this->getJson('/api/forecasts?demo=1', $this->headersFor('staff'))->assertForbidden();
        $headers = $this->headersFor();
        $this->getJson('/api/forecasts?demo=invalid', $headers)->assertUnprocessable();
        $this->getJson('/api/forecasts?demo=1&months=9999', $headers)->assertUnprocessable();
    }
}
