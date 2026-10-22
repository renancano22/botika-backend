<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Report;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Reports: the period never starts before the first record in the system (no empty days before the system was used). */
class ReportPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_report_period_starts_at_the_first_record(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
        $headers = ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];

        // No records yet: the chosen period is kept (there is nothing to move it to).
        $this->getJson('/api/reports/shortages', $headers)->assertOk()
            ->assertJsonPath('date_range', '2026-09-22 to 2026-10-22')->assertJsonPath('records_since', null)->assertJsonPath('chosen_from', null);

        // The first record is from October 5.
        $ana = Resident::create(['name' => 'Ana Cruz', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09170000001', 'qr_code' => 'BBC-000001']);
        $med = Medicine::create(['medicine_name' => 'Multivitamin Syrup', 'category' => 'Vitamins', 'unit' => 'bottle', 'reorder_level' => 20]);
        $r = MedicineRequest::create(['resident_id' => $ana->resident_id, 'request_type' => 'restock', 'status' => 'pending', 'request_date' => '2026-10-05 09:00:00']);
        RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $med->medicine_id, 'quantity' => 5]);

        // "Last 30 days" (Sep 22 – Oct 22) starts on Oct 5 instead, and says so.
        foreach (['shortages', 'usage', 'most_requested', 'dispensing', 'transactions'] as $type) {
            $this->getJson("/api/reports/{$type}", $headers)->assertOk()
                ->assertJsonPath('date_range', '2026-10-05 to 2026-10-22')
                ->assertJsonPath('records_since', '2026-10-05')->assertJsonPath('chosen_from', '2026-09-22');
        }
        $this->assertSame('2026-10-05 to 2026-10-22', Report::latest('report_id')->first()->date_range); // report history too

        // A period that starts after the first record is not changed; inventory status is "as of" today.
        $this->getJson('/api/reports/shortages?from=2026-10-10&to=2026-10-22', $headers)->assertOk()
            ->assertJsonPath('date_range', '2026-10-10 to 2026-10-22')->assertJsonPath('chosen_from', null);
        $this->getJson('/api/reports/inventory', $headers)->assertOk()->assertJsonPath('date_range', 'As of 2026-10-22');

        // A period entirely before the first record keeps its dates (and has no data).
        $this->getJson('/api/reports/usage?from=2026-09-01&to=2026-09-30', $headers)->assertOk()
            ->assertJsonPath('date_range', '2026-09-01 to 2026-09-30')->assertJsonPath('rows', []);
    }
}
