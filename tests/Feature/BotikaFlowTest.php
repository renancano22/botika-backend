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
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
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
        // Two batches were used, each logged as a "dispensed" stock transaction.
        $this->assertDatabaseCount('stock_transactions', 2);
        $this->assertDatabaseHas('stock_transactions', ['inventory_id' => $soon->inventory_id, 'type' => 'dispensed', 'quantity' => 8]);
    }

    public function test_restock_request_is_fulfilled_when_stock_arrives(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 5]);

        $reg = $this->postJson('/api/register', [
            'name' => 'Pedro Reyes', 'email' => 'pedro@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09181234567',
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
            'name' => 'Ana Cruz', 'email' => 'ana@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09191234567',
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
            'barangay' => 'Zone I Poblacion', 'contact_no' => '+63 917 555 0001',
        ])->assertCreated()->assertJsonPath('user.resident.contact_no', '09175550001');

        // Same number written differently is still recognised as already registered.
        $this->postJson('/api/register', [
            'name' => 'Someone Else', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Zone I Poblacion', 'contact_no' => '0917-555-0001',
        ])->assertStatus(422);

        $this->postJson('/api/login', ['login' => '09175550001', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['login' => '639175550001', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['login' => '09175550001', 'password' => 'wrongpass'])->assertStatus(422);

        User::factory()->create(['email' => 'staff@example.com']);
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'password'])->assertOk();
    }

    public function test_approved_requests_reserve_stock_and_stock_changes_are_logged(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Amlodipine 5mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 2]);

        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $medicine->medicine_id, 'quantity' => 10, 'expiration_date' => now()->addYear()->toDateString()], $this->token($staff))
            ->assertCreated();
        $this->assertDatabaseHas('stock_transactions', ['medicine_id' => $medicine->medicine_id, 'type' => 'stock_in', 'quantity' => 10, 'performed_by' => $staff->user_id]);

        $register = fn (string $name, string $phone) => $this->postJson('/api/register', [
            'name' => $name, 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Zone I Poblacion', 'contact_no' => $phone,
        ])->assertCreated();
        $a = $register('Resident A', '09170000001');
        $b = $register('Resident B', '09170000002');

        $requestA = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 8]]],
            ['Authorization' => 'Bearer ' . $a->json('token')])->assertCreated()->json('request_id');
        $this->postJson("/api/requests/{$requestA}/approve", [], $this->token($staff))->assertOk();

        // 10 on the shelf, 8 reserved for resident A: resident B cannot request 5.
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 5]]],
            ['Authorization' => 'Bearer ' . $b->json('token')])->assertStatus(422);
        // ...and a walk-in cannot take the reserved stock either.
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => $b->json('user.resident.qr_code'), 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 5]]],
            $this->token($staff))->assertStatus(422);

        $this->getJson('/api/medicines', $this->token($staff))
            ->assertJsonPath('0.available_stock', 10)
            ->assertJsonPath('0.reserved_stock', 8)
            ->assertJsonPath('0.free_stock', 2);

        // Stock-out needs a reason and is logged.
        $batchId = \App\Models\Inventory::first()->inventory_id;
        $this->postJson("/api/inventory/{$batchId}/stock-out", ['quantity' => 1], $this->token($staff))->assertStatus(422);
        $this->postJson("/api/inventory/{$batchId}/stock-out", ['quantity' => 1, 'reason' => 'Damaged'], $this->token($staff))->assertOk();
        $this->assertDatabaseHas('stock_transactions', ['type' => 'stock_out', 'quantity' => 1, 'reason' => 'Damaged']);

        $this->getJson('/api/inventory/transactions', $this->token($staff))->assertOk()->assertJsonCount(2);
    }

    public function test_registration_validation_and_barangay_address(): void
    {
        $base = ['name' => 'Jose Rizal', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Zone I Poblacion', 'contact_no' => '09170001111'];

        $this->postJson('/api/register', $base + ['email' => 'jose@yahoo.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/register', ['barangay' => 'Manila'] + $base)->assertStatus(422)->assertJsonValidationErrors('barangay');
        $this->postJson('/api/register', ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/register', ['name' => 'J0se 123'] + $base)->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/register', $base + ['email' => 'Jose@Gmail.com', 'address_line' => 'Purok 3'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'jose@gmail.com')
            ->assertJsonPath('user.resident.address', 'Purok 3, Zone I Poblacion, Bulan, Sorsogon');

        $this->getJson('/api/barangays')->assertOk()->assertJsonCount(63);
    }

    public function test_forgot_password_with_sms_code(): void
    {
        $sent = null;
        $this->mock(\App\Services\SmsService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendTo')->andReturnUsing(function ($number, $message) use (&$sent) {
                $sent = $message;
                return 'logged';
            });
        });

        $this->postJson('/api/register', [
            'name' => 'Nena Reyes', 'password' => 'oldpass123', 'password_confirmation' => 'oldpass123',
            'barangay' => 'Bical', 'contact_no' => '09172223333',
        ])->assertCreated();

        // Unknown accounts get the same answer (no hint whether the number is registered).
        $this->postJson('/api/forgot-password', ['login' => '09999999999'])->assertOk();
        $this->assertNull($sent);

        $this->postJson('/api/forgot-password', ['login' => '09172223333'])->assertOk();
        $this->assertNotNull($sent);
        preg_match('/\b(\d{6})\b/', $sent, $m);
        $code = $m[1];

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/reset-password', ['login' => '09172223333', 'code' => $wrong, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertStatus(422);
        $this->postJson('/api/reset-password', ['login' => '09172223333', 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertOk();

        $this->postJson('/api/login', ['login' => '09172223333', 'password' => 'oldpass123'])->assertStatus(422);
        $this->postJson('/api/login', ['login' => '09172223333', 'password' => 'newpass123'])->assertOk();
        // A used code cannot be used again.
        $this->postJson('/api/reset-password', ['login' => '09172223333', 'code' => $code, 'password' => 'another123', 'password_confirmation' => 'another123'])
            ->assertStatus(422);
    }

    public function test_forecasting_techniques(): void
    {
        $series = [10, 20, 30, 40];
        $this->assertEqualsWithDelta(30.0, ForecastService::movingAverage($series), 0.001);
        $this->assertEqualsWithDelta(50.0, ForecastService::linearRegression($series), 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::exponentialSmoothing($series), 0.01);
    }
}
