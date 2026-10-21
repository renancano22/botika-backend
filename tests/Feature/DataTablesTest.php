<?php

namespace Tests\Feature;

use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Notification;
use App\Models\Report;
use App\Models\RequestItem;
use App\Models\Resident;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data tables: server-side pagination (rows per page), "Showing x–y of z" information, date ranges,
 * search, sorting, and that every record (old and new) can be reached without being changed or repeated.
 */
class DataTablesTest extends TestCase
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

    private function resident(string $name, string $code, ?User $user = null): Resident
    {
        return Resident::create(['user_id' => $user?->user_id, 'name' => $name, 'address' => 'Gate, Bulan, Sorsogon',
            'contact_no' => '0917' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), 'qr_code' => $code]);
    }

    /** Every page of a list, one after the other: [ids in order, last response]. */
    private function allPages(string $url, array $params, array $headers, string $key): array
    {
        $ids = [];
        $page = 1;
        do {
            $res = $this->getJson($url . '?' . http_build_query($params + ['page' => $page]), $headers)->assertOk();
            $meta = $res->json('meta');
            // "Showing from–to of total": from/to continue across pages (they are the row numbers).
            $this->assertSame(($page - 1) * $meta['per_page'] + 1, $meta['from']);
            $this->assertSame($meta['from'] + count($res->json('data')) - 1, $meta['to']);
            $ids = [...$ids, ...array_column($res->json('data'), $key)];
            $page++;
        } while ($page <= $meta['last_page']);

        return [$ids, $res];
    }

    /**
     * 10 dispensing records on Oct 6, 2026 (3 of them at exactly the same time) and 2 on other days.
     * @return array{0: User, 1: Medicine, 2: Resident, 3: Resident}
     */
    private function dispensingRecords(): array
    {
        $staff = User::factory()->create(['name' => 'Joy Mercado']);
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $amox = Medicine::create(['medicine_name' => 'Amoxicillin 500mg', 'category' => 'Antibiotic', 'unit' => 'capsule', 'reorder_level' => 5]);
        $ana = $this->resident('Ana Cruz', 'BBC-000001');
        $ben = $this->resident('Ben Ramos', 'BBC-000002');

        $give = function (Resident $r, Medicine $m, int $qty, string $at) use ($staff) {
            $req = MedicineRequest::create(['resident_id' => $r->resident_id, 'request_type' => 'medicine', 'request_date' => $at, 'status' => 'dispensed', 'reviewed_by' => $staff->user_id, 'reviewed_at' => $at]);
            RequestItem::create(['request_id' => $req->request_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
            $d = Dispensing::create(['request_id' => $req->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => $at]);
            DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $m->medicine_id, 'quantity' => $qty]);
        };
        $give($ana, $para, 4, '2026-10-05 15:00:00');
        for ($i = 0; $i < 10; $i++) {
            $time = $i < 3 ? '2026-10-06 09:00:00' : sprintf('2026-10-06 %02d:15:00', 9 + $i);
            $give($i % 2 ? $ben : $ana, $i === 9 ? $amox : $para, $i + 1, $time);
        }
        $give($ben, $amox, 7, '2026-10-07 10:00:00');

        return [$staff, $para, $ana, $ben];
    }

    public function test_rows_per_page_shows_every_record_of_a_date_once(): void
    {
        [$staff] = $this->dispensingRecords();
        $headers = $this->token($staff);
        $oct6 = ['from' => '2026-10-06', 'to' => '2026-10-06'];

        // 5 rows per page: 10 records on Oct 6 → page 1 has 5, page 2 has the other 5.
        $page1 = $this->getJson('/api/dispensing?' . http_build_query($oct6 + ['per_page' => 5, 'page' => 1]), $headers)->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 10)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 5);
        $page2 = $this->getJson('/api/dispensing?' . http_build_query($oct6 + ['per_page' => 5, 'page' => 2]), $headers)->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.from', 6)->assertJsonPath('meta.to', 10);
        $ids = [...array_column($page1->json('data'), 'dispensing_id'), ...array_column($page2->json('data'), 'dispensing_id')];
        $this->assertCount(10, array_unique($ids)); // none repeated or skipped, even with the same time
        $this->assertSame(Dispensing::whereDate('dispensed_at', '2026-10-06')->orderByDesc('dispensed_at')->orderByDesc('dispensing_id')->pluck('dispensing_id')->all(), $ids);

        // Every page size gives the same records in the same order (newest first).
        foreach ([5, 10, 25, 50] as $size) {
            [$all] = $this->allPages('/api/dispensing', $oct6 + ['per_page' => $size], $headers, 'dispensing_id');
            $this->assertSame($ids, $all);
        }

        // The summary counts all matching records, not only the page shown.
        $page1->assertJsonPath('summary.records', 10)->assertJsonPath('summary.residents', 2)->assertJsonPath('summary.total_quantity', 55);

        // A page after the last one (e.g. the list became shorter) shows the last page.
        $this->getJson('/api/dispensing?' . http_build_query($oct6 + ['per_page' => 5, 'page' => 9]), $headers)
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 6);

        // Previous records stay available: all dates = 12 records, newest first; "oldest" reverses the order.
        [$newest] = $this->allPages('/api/dispensing', ['per_page' => 5], $headers, 'dispensing_id');
        [$oldest] = $this->allPages('/api/dispensing', ['per_page' => 5, 'sort' => 'oldest'], $headers, 'dispensing_id');
        $this->assertCount(12, $newest);
        $this->assertSame(array_reverse($newest), $oldest);
        $this->assertSame('2026-10-07', substr($this->getJson('/api/dispensing?per_page=5', $headers)->json('data.0.dispensed_at'), 0, 10));

        // Date range over several days, and search (resident, Resident ID, medicine, request number).
        $this->getJson('/api/dispensing?per_page=5&from=2026-10-05&to=2026-10-06', $headers)->assertJsonPath('meta.total', 11);
        $this->getJson('/api/dispensing?per_page=5&search=Ben', $headers)->assertJsonPath('meta.total', 6);
        $this->getJson('/api/dispensing?per_page=5&search=BBC-000002', $headers)->assertJsonPath('meta.total', 6);
        $this->getJson('/api/dispensing?per_page=5&search=amoxicillin', $headers)->assertJsonPath('meta.total', 2)->assertJsonPath('summary.total_quantity', 17);
        $requestId = Dispensing::orderBy('dispensing_id')->first()->request_id;
        $this->getJson('/api/dispensing?per_page=5&search=' . urlencode("Request No. {$requestId}"), $headers)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.request_id', $requestId);

        // A wrong date range is refused with a clear message.
        $this->getJson('/api/dispensing?per_page=5&from=2026-10-07&to=2026-10-06', $headers)->assertStatus(422)->assertJsonValidationErrors('to');

        // Viewing never changes or removes records; the old list (without per_page) still works.
        $this->assertSame(12, Dispensing::count());
        $this->assertCount(12, $this->getJson('/api/dispensing', $headers)->assertOk()->json());
    }

    public function test_residents_only_see_their_own_records_in_every_table(): void
    {
        [$staff, , $ana] = $this->dispensingRecords();
        $user = User::factory()->create(['role' => User::ROLE_RESIDENT, 'name' => 'Ana Cruz', 'email' => 'ana.cruz@gmail.com']);
        $ana->update(['user_id' => $user->user_id]);
        $headers = $this->token($user);

        // My Medicine History: only Ana's 6 records; searching another resident's name finds nothing.
        $this->getJson('/api/dispensing?per_page=5', $headers)->assertOk()->assertJsonPath('meta.total', 6)->assertJsonPath('summary.residents', 1);
        $this->getJson('/api/dispensing?per_page=5&search=Ben', $headers)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/requests?per_page=5', $headers)->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson('/api/requests', $headers)->assertOk()->assertJsonCount(6);

        // Staff and admin tables stay closed to residents.
        foreach (['/api/users', '/api/inventory', '/api/inventory/transactions', '/api/reports'] as $url) {
            $this->getJson("{$url}?per_page=5", $headers)->assertForbidden();
        }
        $this->getJson('/api/requests/counts', $headers)->assertForbidden();
        $this->getJson('/api/users?per_page=5', $this->token($staff))->assertForbidden(); // admin only
    }

    public function test_requests_table_filters_search_dates_and_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $headers = $this->token($admin);
        $med = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        $ana = $this->resident('Ana Cruz', 'BBC-000001');
        $ben = $this->resident('Ben Ramos', 'BBC-000002');
        foreach (range(1, 12) as $i) {
            $r = MedicineRequest::create(['resident_id' => ($i % 3 ? $ana : $ben)->resident_id, 'request_type' => $i > 10 ? 'restock' : 'medicine',
                'request_date' => sprintf('2026-10-%02d 08:00:00', $i), 'status' => $i % 4 ? 'pending' : 'rejected']);
            RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $med->medicine_id, 'quantity' => 1]);
        }

        [$ids] = $this->allPages('/api/requests', ['per_page' => 5], $headers, 'request_id');
        $this->assertSame(MedicineRequest::orderByDesc('request_date')->pluck('request_id')->all(), $ids); // latest first, all 12
        $this->getJson('/api/requests?per_page=5&type=medicine&status=pending', $headers)->assertJsonPath('meta.total', 8);
        $this->getJson('/api/requests?per_page=5&from=2026-10-03&to=2026-10-06', $headers)->assertJsonPath('meta.total', 4);
        $this->getJson('/api/requests?per_page=5&search=Ben%20Ramos', $headers)->assertJsonPath('meta.total', 4);
        $this->getJson('/api/requests?per_page=5&search=cetirizine&sort=oldest', $headers)
            ->assertJsonPath('meta.total', 12)->assertJsonPath('data.0.request_id', $ids[11]);

        // The status tab numbers follow the same search and dates.
        $this->getJson('/api/requests/counts?type=medicine&from=2026-10-01&to=2026-10-08', $headers)->assertOk()
            ->assertJsonPath('pending', 6)->assertJsonPath('rejected', 2)->assertJsonPath('all', 8);
    }

    public function test_inventory_batches_and_stock_transactions_tables(): void
    {
        $staff = User::factory()->create();
        $headers = $this->token($staff);
        $med = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 5]);
        $batches = collect(range(1, 7))->map(fn ($i) => Inventory::create(['medicine_id' => $med->medicine_id, 'quantity' => $i === 7 ? 0 : 10,
            'expiration_date' => now()->addMonths($i * 2)->toDateString()]));
        Inventory::create(['medicine_id' => $med->medicine_id, 'quantity' => 5, 'expiration_date' => now()->subDay()->toDateString()]);

        // Batches with stock left (7), earliest expiration first; used-up batches are kept and listed separately.
        [$ids] = $this->allPages('/api/inventory', ['per_page' => 5], $headers, 'inventory_id');
        $this->assertCount(7, $ids);
        $this->getJson('/api/inventory?per_page=5&expiry=empty', $headers)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.inventory_id', $batches[6]->inventory_id);
        $this->getJson('/api/inventory?per_page=5&expiry=expired', $headers)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.expiry_status', 'expired');
        $this->getJson('/api/inventory?per_page=5&sort=newest', $headers)->assertJsonPath('data.0.inventory_id', Inventory::where('quantity', '>', 0)->max('inventory_id'));

        // An expiry alert opens the page that contains its batch.
        $last = end($ids);
        $this->getJson("/api/inventory?per_page=5&focus={$last}", $headers)->assertJsonPath('meta.current_page', 2)
            ->assertJsonFragment(['inventory_id' => $last]);

        // Stock transactions: every change is listed, newest first, with type, date and medicine filters.
        foreach (range(1, 6) as $i) {
            StockTransaction::create(['medicine_id' => $med->medicine_id, 'inventory_id' => $batches[0]->inventory_id, 'type' => $i % 2 ? 'stock_in' : 'stock_out',
                'quantity' => $i, 'reason' => $i % 2 ? 'New batch received' : 'Damaged', 'performed_by' => $staff->user_id])
                ->forceFill(['created_at' => "2026-10-0{$i} 10:00:00"])->save();
        }
        [$tx] = $this->allPages('/api/inventory/transactions', ['per_page' => 5], $headers, 'transaction_id');
        $this->assertSame(StockTransaction::orderByDesc('created_at')->pluck('transaction_id')->all(), $tx);
        $this->getJson('/api/inventory/transactions?per_page=5&type=stock_out', $headers)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/inventory/transactions?per_page=5&from=2026-10-02&to=2026-10-04', $headers)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/inventory/transactions?per_page=5&search=losartan', $headers)->assertJsonPath('meta.total', 6)
            ->assertJsonPath('data.0.reason_text', 'Damaged');
    }

    public function test_medicine_users_sms_log_and_report_history_tables(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Reyes']);
        $headers = $this->token($admin);

        // Medicine List: filtered by the computed stock status, then paginated; "unrecorded" counts all of them.
        foreach (['Ascorbic Acid', 'Biogesic', 'Carbocisteine', 'Dextromethorphan', 'Erythromycin', 'Ferrous Sulfate'] as $i => $name) {
            $m = Medicine::create(['medicine_name' => $name, 'generic_name' => $name, 'category' => 'Vitamins', 'unit' => 'tablet', 'reorder_level' => 5]);
            if ($i < 2) Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        }
        $this->getJson('/api/medicines?per_page=5', $headers)->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 6)->assertJsonPath('unrecorded', 4);
        $this->getJson('/api/medicines?per_page=5&page=2', $headers)->assertJsonPath('data.0.medicine_name', 'Ferrous Sulfate');
        $this->getJson('/api/medicines?per_page=5&status=available', $headers)->assertJsonPath('meta.total', 2);
        $this->assertCount(6, $this->getJson('/api/medicines', $headers)->json()); // dropdowns still get the whole list

        // User accounts: role, status, search and sorting.
        User::factory()->count(6)->create();
        User::factory()->create(['name' => 'Zed Old', 'is_active' => false])->forceFill(['created_at' => '2025-01-01 08:00:00'])->save();
        $this->getJson('/api/users?per_page=5', $headers)->assertJsonPath('meta.total', 8)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/users?per_page=5&status=inactive', $headers)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Zed Old');
        $this->getJson('/api/users?per_page=5&sort=oldest', $headers)->assertJsonPath('data.0.name', 'Zed Old');
        $this->getJson('/api/users?per_page=5&role=admin', $headers)->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('password_hash', $this->getJson('/api/users?per_page=50', $headers)->getContent());

        // SMS log: newest first, search, status and dates.
        $ana = $this->resident('Ana Cruz', 'BBC-000001');
        foreach (range(1, 7) as $i) {
            Notification::create(['resident_id' => $ana->resident_id, 'type' => 'announcement', 'message' => "Announcement number {$i}",
                'channel' => $i === 7 ? 'app' : 'sms', 'status' => $i === 3 ? 'failed' : 'sent', 'sent_at' => "2026-10-0{$i} 09:00:00"]);
        }
        $this->getJson('/api/notifications?per_page=5', $headers)->assertJsonPath('meta.total', 7)->assertJsonPath('data.0.message', 'Announcement number 7');
        $this->getJson('/api/notifications?per_page=5&status=failed', $headers)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/notifications?per_page=5&status=app', $headers)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/notifications?per_page=5&search=Ana', $headers)->assertJsonPath('meta.total', 7);
        $this->getJson('/api/notifications?per_page=5&from=2026-10-02&to=2026-10-03', $headers)->assertJsonPath('meta.total', 2);

        // Report history: kept in full (not only the last 100), newest first.
        foreach (range(1, 105) as $i) {
            Report::create(['generated_by' => $admin->user_id, 'report_type' => $i % 2 ? 'usage' : 'inventory', 'date_range' => 'As of 2026-10-06',
                'generated_at' => now()->subMinutes(200 - $i)]);
        }
        $this->getJson('/api/reports?per_page=50', $headers)->assertJsonPath('meta.total', 105)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/reports?per_page=50&page=3', $headers)->assertJsonCount(5, 'data')->assertJsonPath('meta.from', 101);
        $this->getJson('/api/reports?per_page=5&type=usage', $headers)->assertJsonPath('meta.total', 53);
    }
}
