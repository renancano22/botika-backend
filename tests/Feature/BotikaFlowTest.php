<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\ForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotikaFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Forget the cached authenticated user so each request uses its own bearer token. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_resident_request_is_approved_and_dispensed_with_fefo(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $expired = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 50, 'expiration_date' => now()->subDay()->toDateString()]);
        $soon = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 8, 'expiration_date' => now()->addDays(10)->toDateString()]);
        $later = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 20, 'expiration_date' => now()->addYear()->toDateString()]);

        $reg = $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'address' => 'Zone 2, Bulan', 'contact_no' => '09171234567',
        ])->assertCreated();
        $residentHeaders = ['Authorization' => 'Bearer ' . $reg->json('token')];
        $qr = $reg->json('user.resident.qr_code');
        $this->assertSame('BBC-000001', $qr);

        // Expired stock is not counted: 28 available, so 30 is refused.
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 30]]], $residentHeaders)
            ->assertStatus(422);

        $requestId = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 10]]], $residentHeaders)
            ->assertCreated()->json('request_id');

        // Residents cannot approve.
        $this->postJson("/api/requests/{$requestId}/approve", [], $residentHeaders)->assertForbidden();

        $this->postJson("/api/requests/{$requestId}/approve", [], $this->token($staff))
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->postJson('/api/dispensing', ['request_id' => $requestId, 'qr_code' => 'WRONG'], $this->token($staff))->assertStatus(422);
        $this->postJson('/api/dispensing', ['request_id' => $requestId, 'qr_code' => $qr], $this->token($staff))->assertCreated();

        $this->assertSame(50, $expired->fresh()->quantity); // expired batch untouched
        $this->assertSame(0, $soon->fresh()->quantity);     // first-expiry-first-out
        $this->assertSame(18, $later->fresh()->quantity);
        $this->assertDatabaseHas('requests', ['request_id' => $requestId, 'status' => 'dispensed']);
        $this->assertDatabaseCount('notifications', 2); // approved + dispensed SMS
    }

    public function test_restock_request_is_fulfilled_when_stock_arrives(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 5]);

        $reg = $this->postJson('/api/register', [
            'name' => 'Pedro Reyes', 'email' => 'pedro@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'address' => 'Zone 3, Bulan', 'contact_no' => '09181234567',
        ])->assertCreated();
        $headers = ['Authorization' => 'Bearer ' . $reg->json('token')];

        $id = $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 30]]], $headers)
            ->assertCreated()->json('request_id');

        $this->postJson("/api/requests/{$id}/approve", [], $this->token($admin))->assertOk();
        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $medicine->medicine_id, 'quantity' => 100, 'expiration_date' => now()->addYear()->toDateString()], $this->token($admin))
            ->assertCreated();

        $this->assertDatabaseHas('requests', ['request_id' => $id, 'status' => 'fulfilled']);
    }

    public function test_staff_cannot_access_admin_modules(): void
    {
        $staff = User::factory()->create();
        $this->getJson('/api/users', $this->token($staff))->assertForbidden();
        $this->getJson('/api/reports/usage', $this->token($staff))->assertForbidden();
        $this->getJson('/api/forecasts', $this->token($staff))->assertForbidden();
    }

    public function test_medicine_with_history_cannot_be_deleted_and_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        $unused = Medicine::create(['medicine_name' => 'Typo entry', 'category' => 'Other', 'unit' => 'tablet', 'reorder_level' => 5]);

        $reg = $this->postJson('/api/register', [
            'name' => 'Ana Cruz', 'email' => 'ana@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'address' => 'Zone 4, Bulan', 'contact_no' => '09191234567',
        ])->assertCreated();
        $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 5]]],
            ['Authorization' => 'Bearer ' . $reg->json('token')])->assertCreated();

        $this->deleteJson("/api/medicines/{$medicine->medicine_id}", [], $this->token($admin))->assertStatus(422);
        $this->deleteJson("/api/medicines/{$unused->medicine_id}", [], $this->token($admin))->assertNoContent();

        $this->putJson("/api/users/{$admin->user_id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'staff'], $this->token($admin))
            ->assertStatus(422);
    }

    public function test_resident_can_register_without_email_and_log_in_with_mobile_number(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Lola Nena', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'address' => 'Zone 5, Bulan', 'contact_no' => '+63 917 555 0001',
        ])->assertCreated()->assertJsonPath('user.resident.contact_no', '09175550001');

        // Same number written differently is still recognised as already registered.
        $this->postJson('/api/register', [
            'name' => 'Someone Else', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'address' => 'Zone 6, Bulan', 'contact_no' => '0917-555-0001',
        ])->assertStatus(422);

        $this->postJson('/api/login', ['login' => '09175550001', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['login' => '639175550001', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['login' => '09175550001', 'password' => 'wrongpass'])->assertStatus(422);

        User::factory()->create(['email' => 'staff@example.com']);
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'password'])->assertOk();
    }

    public function test_forecasting_techniques(): void
    {
        $series = [10, 20, 30, 40];
        $this->assertEqualsWithDelta(30.0, ForecastService::movingAverage($series), 0.001);
        $this->assertEqualsWithDelta(50.0, ForecastService::linearRegression($series), 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::exponentialSmoothing($series), 0.01);
    }
}
