<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartialMedicineReviewTest extends TestCase
{
    use RefreshDatabase;

    private array $residentHeaders;
    private array $staffHeaders;
    private MedicineRequest $req;
    private array $medicines;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sms.semaphore.api_key' => '']);
        $resident = $this->postJson('/api/register', ['name' => 'Ana Dela Peña', 'barangay' => 'Gate', 'contact_no' => '09171234567',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'])->assertCreated();
        $this->residentHeaders = ['Authorization' => 'Bearer ' . $resident->json('token')];
        $this->staffHeaders = ['Authorization' => 'Bearer ' . User::factory()->create()->createToken('test')->plainTextToken];
        $this->req = MedicineRequest::create(['resident_id' => User::where('name', 'Ana Dela Peña')->firstOrFail()->resident->resident_id,
            'request_type' => 'medicine', 'request_date' => now(), 'status' => 'pending']);
        foreach (['First antibiotic', 'Second antibiotic', 'Vitamin'] as $i => $name) {
            $m = Medicine::create(['medicine_name' => $name, 'category' => 'Medicine', 'unit' => 'tablet', 'requires_prescription' => $i < 2]);
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => 20, 'expiration_date' => now()->addYear()->toDateString()]);
            $this->medicines[] = $m;
            $this->req->items()->create(['medicine_id' => $m->medicine_id, 'quantity' => 2]);
            if ($i < 2) $this->req->prescriptions()->create(['file_name' => "Doctor-{$i}.pdf", 'mime_type' => 'application/pdf',
                'file_data' => base64_encode('%PDF-1.4 test'), 'size' => 13, 'medicine_ids' => [$m->medicine_id]]);
        }
        $this->req->load(['items.medicine', 'prescriptions', 'resident']);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function payload(array $decisions = ['approved', 'rejected', 'approved']): array
    {
        return ['item_reviews' => $this->req->items->map(fn ($item, $index) => ['request_item_id' => $item->request_item_id,
            'decision' => $decisions[$index], 'reason' => $decisions[$index] === 'rejected' ? 'Prescription does not match this medicine.' : null])->all(),
            'prescription_verified' => true, 'verified_prescription_ids' => [$this->req->prescriptions[0]->prescription_id]];
    }

    private function review(array $data)
    {
        return $this->postJson('/api/requests/' . $this->req->request_id . '/review', $data, $this->staffHeaders);
    }

    public function test_mixed_review_reserves_and_dispenses_only_approved_medicines_and_keeps_rejected_history(): void
    {
        // An unavailable rejected item must not prevent the valid medicines from being approved.
        Inventory::where('medicine_id', $this->medicines[1]->medicine_id)->update(['quantity' => 0]);
        $this->review($this->payload())->assertOk()->assertJsonPath('status', 'approved')
            ->assertJsonPath('items.0.review_status', 'approved')->assertJsonPath('items.1.review_status', 'rejected')
            ->assertJsonPath('items.1.rejection_reason', 'Prescription does not match this medicine.');
        $inventory = app(InventoryService::class);
        foreach ($this->medicines as $i => $m) $this->assertSame($i === 1 ? 0 : 2, $inventory->reservedStock($m->medicine_id));
        $this->assertSame(2, count($inventory->reservedByMedicine()));
        $demand = app(\App\Services\ForecastService::class)->topRequestedMedicines(now()->subDay());
        $this->assertCount(2, $demand);
        $this->assertNotContains($this->medicines[1]->medicine_id, array_column($demand, 'medicine_id'));
        $this->assertSame(3, $this->req->fresh()->items()->count());
        $message = DB::table('notifications')->where('request_id', $this->req->request_id)->where('type', 'approved')->value('message');
        $this->assertStringContainsString('PARTIALLY APPROVED', $message);
        $this->assertStringContainsString('Not approved: Second antibiotic: Prescription does not match', $message);
        $this->assertStringNotContainsString('Second antibiotic x2', $message);
        $this->getJson('/api/requests/' . $this->req->request_id, $this->residentHeaders)->assertOk()->assertJsonCount(3, 'items');
        $release = $this->postJson('/api/dispensing', ['request_id' => $this->req->request_id, 'qr_code' => $this->req->resident->qr_code,
            'prescription_checked' => true], $this->staffHeaders)->assertCreated()->assertJsonCount(2, 'items');
        $this->assertSame([$this->medicines[0]->medicine_id, $this->medicines[2]->medicine_id], array_column($release->json('items'), 'medicine_id'));
        $this->assertSame(18, $inventory->availableStock($this->medicines[0]->medicine_id));
        $this->assertSame(0, DB::table('dispensing_items')->where('medicine_id', $this->medicines[1]->medicine_id)->count());
        $this->assertDatabaseHas('request_items', ['request_item_id' => $this->req->items[1]->request_item_id, 'review_status' => 'rejected']);
    }

    public function test_only_rejected_medicine_can_be_requested_again_while_approved_items_wait_for_pickup(): void
    {
        $this->review($this->payload())->assertOk();
        foreach ([1, 0] as $i) {
            $file = ['name' => 'corrected.pdf', 'data' => 'data:application/pdf;base64,' . base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF"),
                'medicine_ids' => [$this->medicines[$i]->medicine_id]];
            $res = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $this->medicines[$i]->medicine_id, 'quantity' => 2]],
                'prescriptions' => [$file]], $this->residentHeaders);
            $i === 1 ? $res->assertCreated() : $res->assertUnprocessable()->assertJsonValidationErrors('items');
        }
    }

    public function test_all_rejected_needs_reasons_but_no_prescription_confirmation_or_stock(): void
    {
        $data = $this->payload(['rejected', 'rejected', 'rejected']);
        unset($data['prescription_verified'], $data['verified_prescription_ids']);
        $this->review($data)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('claim_by', null);
        $this->assertSame([], app(InventoryService::class)->reservedByMedicine());
        $this->assertDatabaseHas('notifications', ['request_id' => $this->req->request_id, 'type' => 'rejected']);
    }

    public function test_every_approved_prescription_medicine_needs_its_own_valid_coverage_and_failed_review_rolls_back(): void
    {
        $this->review($this->payload(['approved', 'approved', 'approved']))->assertUnprocessable()->assertJsonValidationErrors('verified_prescription_ids');
        $this->assertDatabaseHas('requests', ['request_id' => $this->req->request_id, 'status' => 'pending']);
        $this->assertSame(0, $this->req->items()->whereNotNull('review_status')->count());
        $data = $this->payload(['approved', 'approved', 'approved']);
        $data['verified_prescription_ids'] = $this->req->prescriptions->pluck('prescription_id')->all();
        $this->review($data)->assertOk();
    }

    public function test_a_valid_file_is_sufficient_even_if_another_file_for_the_same_medicine_is_unchecked(): void
    {
        $this->req->prescriptions[1]->update(['medicine_ids' => [$this->medicines[0]->medicine_id, $this->medicines[1]->medicine_id]]);
        $this->review($this->payload())->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_incomplete_duplicate_foreign_decisions_blank_reasons_and_foreign_files_are_refused(): void
    {
        $base = $this->payload();
        $cases = [];
        $data = $base; array_pop($data['item_reviews']); $cases[] = $data;
        $data = $base; $data['item_reviews'][1]['request_item_id'] = $data['item_reviews'][0]['request_item_id']; $cases[] = $data;
        $data = $base; $data['item_reviews'][1]['request_item_id'] = 999999; $cases[] = $data;
        $data = $base; $data['item_reviews'][1]['reason'] = '   '; $cases[] = $data;
        $data = $base; $data['item_reviews'][1]['reason'] = '1234'; $cases[] = $data;
        $data = $base; $data['item_reviews'][1]['reason'] = str_repeat('a', 256); $cases[] = $data;
        $data = $base; $data['verified_prescription_ids'] = [999999]; $cases[] = $data;
        $data = $base; $data['prescription_verified'] = false; $cases[] = $data;
        foreach ($cases as $data) $this->review($data)->assertUnprocessable();
        $this->assertSame(0, $this->req->items()->whereNotNull('review_status')->count());
        $this->assertDatabaseMissing('notifications', ['request_id' => $this->req->request_id]);
    }

    public function test_stock_failure_rolls_back_decisions_and_repeated_review_never_sends_another_notice(): void
    {
        Inventory::where('medicine_id', $this->medicines[0]->medicine_id)->update(['quantity' => 0]);
        $this->review($this->payload())->assertUnprocessable();
        $this->assertSame(0, $this->req->items()->whereNotNull('review_status')->count());
        Inventory::where('medicine_id', $this->medicines[0]->medicine_id)->update(['quantity' => 20]);
        $this->review($this->payload())->assertOk();
        $this->review($this->payload())->assertUnprocessable();
        $this->assertSame(1, DB::table('notifications')->where('request_id', $this->req->request_id)->count());
    }

    public function test_residents_cannot_review_their_own_request(): void
    {
        $this->postJson('/api/requests/' . $this->req->request_id . '/review', $this->payload(), $this->residentHeaders)->assertForbidden();
    }

    public function test_rejected_prescription_medicines_do_not_block_approval_or_pickup_of_a_non_prescription_medicine(): void
    {
        $data = $this->payload(['rejected', 'rejected', 'approved']);
        $data['verified_prescription_ids'] = [];
        $data['prescription_verified'] = false;
        $this->review($data)->assertOk()->assertJsonPath('prescription_verified_at', null);
        $this->postJson('/api/dispensing', ['request_id' => $this->req->request_id, 'qr_code' => $this->req->resident->qr_code], $this->staffHeaders)
            ->assertCreated()->assertJsonCount(1, 'items')->assertJsonPath('items.0.medicine_id', $this->medicines[2]->medicine_id);
    }

    public function test_reminders_only_name_approved_medicines_and_do_not_repeat(): void
    {
        $this->review($this->payload())->assertOk();
        $this->travel(config('botika.unclaimed_days') - 1)->days();
        $inventory = app(InventoryService::class);
        $this->assertSame(1, $inventory->checkPickupDeadlines()['reminded']);
        $message = DB::table('notifications')->where('request_id', $this->req->request_id)->where('type', 'reminder')->value('message');
        $this->assertStringContainsString('First antibiotic x2', $message);
        $this->assertStringNotContainsString('Second antibiotic', $message);
        $this->assertSame(0, $inventory->checkPickupDeadlines()['reminded']);
    }
}
