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

    public function test_resident_profile_photo_and_password(): void
    {
        $reg = $this->postJson('/api/register', [
            'name' => 'Ana Cruz', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'address_line' => 'Purok 2', 'contact_no' => '09191234567',
        ])->assertCreated();
        $headers = ['Authorization' => 'Bearer ' . $reg->json('token')];

        $this->getJson('/api/profile', $headers)->assertOk()
            ->assertJsonPath('barangay', 'Gate')
            ->assertJsonPath('address_line', 'Purok 2')
            ->assertJsonPath('user.resident.qr_code', 'BBC-000001');

        // Edit information (the Patient ID stays the same).
        $this->putJson('/api/profile', [
            'name' => 'Ana Dela Cruz', 'email' => 'Ana@Gmail.com', 'contact_no' => '0919 765 4321',
            'barangay' => 'Zone II Poblacion', 'address_line' => '',
        ], $headers)->assertOk()
            ->assertJsonPath('user.name', 'Ana Dela Cruz')
            ->assertJsonPath('user.email', 'ana@gmail.com')
            ->assertJsonPath('user.resident.contact_no', '09197654321')
            ->assertJsonPath('user.resident.address', 'Zone II Poblacion, Bulan, Sorsogon')
            ->assertJsonPath('user.resident.qr_code', 'BBC-000001');
        $this->putJson('/api/profile', ['name' => 'Ana', 'contact_no' => '09197654321', 'barangay' => 'Manila'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['barangay']);

        // Profile picture.
        $photo = 'data:image/jpeg;base64,' . base64_encode('fake-jpeg-bytes');
        $this->postJson('/api/profile/photo', ['photo' => $photo], $headers)->assertOk()->assertJsonPath('user.resident.photo', $photo);
        $this->postJson('/api/profile/photo', ['photo' => 'data:text/html;base64,PGI+'], $headers)->assertStatus(422);
        $this->getJson('/api/me', $headers)->assertJsonPath('resident.photo', $photo);
        $this->deleteJson('/api/profile/photo', [], $headers)->assertOk()->assertJsonPath('user.resident.photo', null);

        // Change password: the current one is required.
        $this->putJson('/api/profile/password', ['current_password' => 'wrong123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->putJson('/api/profile/password', ['current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'], $headers)
            ->assertOk();
        $this->postJson('/api/login', ['login' => 'ana@gmail.com', 'password' => 'newpass123'])->assertOk();

        // Staff and admin don't use this page.
        $this->getJson('/api/profile', $this->token(User::factory()->create()))->assertForbidden();
    }

    public function test_notifications_can_be_marked_as_read(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->postJson('/api/register', [
            'name' => 'Lito Garcia', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'contact_no' => '09201234567',
        ])->assertCreated();
        $b = $this->postJson('/api/register', [
            'name' => 'Rosa Garcia', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'contact_no' => '09211234567',
        ])->assertCreated();
        $headersA = ['Authorization' => 'Bearer ' . $a->json('token')];
        $headersB = ['Authorization' => 'Bearer ' . $b->json('token')];

        // The administrator's SMS announcements are also saved as in-app notifications.
        $this->postJson('/api/notifications/announce', ['message' => 'Free check-up on Friday.'], $this->token($admin))->assertOk();
        $this->postJson('/api/notifications/announce', ['message' => 'Closed on Monday.'], $this->token($admin))->assertOk();

        $list = $this->getJson('/api/notifications', $headersA)->assertOk()->assertJsonCount(2)->assertJsonPath('0.type', 'announcement')->json();
        $this->assertNull($list[0]['read_at']);
        $this->getJson('/api/notifications/unread-count', $headersA)->assertJsonPath('unread', 2);

        $this->postJson("/api/notifications/{$list[0]['notification_id']}/read", [], $headersA)->assertOk();
        $this->getJson('/api/notifications/unread-count', $headersA)->assertJsonPath('unread', 1);

        // Another resident's notification cannot be touched.
        $this->postJson("/api/notifications/{$list[1]['notification_id']}/read", [], $headersB)->assertNotFound();

        $this->postJson('/api/notifications/read-all', [], $headersA)->assertOk();
        $this->getJson('/api/notifications/unread-count', $headersA)->assertJsonPath('unread', 0);
        $this->getJson('/api/notifications/unread-count', $headersB)->assertJsonPath('unread', 2);
    }

    public function test_resident_cancels_approved_request_and_unclaimed_requests_expire(): void
    {
        $staff = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $medicine = Medicine::create(['medicine_name' => 'Metformin 500mg', 'category' => 'Antidiabetic', 'unit' => 'tablet', 'reorder_level' => 2]);
        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $medicine->medicine_id, 'quantity' => 10, 'expiration_date' => now()->addYears(2)->toDateString()], $this->token($staff))
            ->assertCreated();

        $register = fn (string $name, string $phone) => $this->postJson('/api/register', [
            'name' => $name, 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'contact_no' => $phone,
        ])->assertCreated();
        $a = $register('Resident A', '09220000001');
        $b = $register('Resident B', '09220000002');
        $headersA = ['Authorization' => 'Bearer ' . $a->json('token')];
        $headersB = ['Authorization' => 'Bearer ' . $b->json('token')];
        $item = fn (int $qty) => ['items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => $qty]]];

        // A's approved request sets aside 8; B asks for a restock of 5 (only 2 are free) and the admin approves it.
        $requestA = $this->postJson('/api/requests', ['request_type' => 'medicine', ...$item(8)], $headersA)->assertCreated()->json('request_id');
        $approved = $this->postJson("/api/requests/{$requestA}/approve", [], $this->token($staff))->assertOk();
        $this->assertNotNull($approved->json('claim_by'));
        $restockB = $this->postJson('/api/requests', ['request_type' => 'restock', ...$item(5)], $headersB)->assertCreated()->json('request_id');
        $this->postJson("/api/requests/{$restockB}/approve", [], $this->token($admin))->assertOk()->assertJsonPath('status', 'approved');

        // Only the owner can cancel; an approved (not yet claimed) request can be cancelled.
        $this->postJson("/api/requests/{$requestA}/cancel", [], $headersB)->assertForbidden();
        $this->postJson("/api/requests/{$requestA}/cancel", [], $headersA)->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('canceller.name', 'Resident A');
        $this->postJson("/api/requests/{$requestA}/cancel", [], $headersA)->assertStatus(422);

        // The 8 set aside go back to the stock, so B's restock request is now fulfilled.
        $this->getJson('/api/medicines', $this->token($staff))->assertJsonPath('0.free_stock', 10);
        $this->assertDatabaseHas('requests', ['request_id' => $restockB, 'status' => 'fulfilled']);
        $this->assertNotNull(\App\Models\MedicineRequest::find($restockB)->fulfilled_at);

        // B's approved request is not claimed within the allowed days, so it is cancelled automatically.
        $requestB = $this->postJson('/api/requests', ['request_type' => 'medicine', ...$item(6)], $headersB)->assertCreated()->json('request_id');
        $this->postJson("/api/requests/{$requestB}/approve", [], $this->token($staff))->assertOk();

        $this->travel(config('botika.unclaimed_days') + 1)->days();
        // Logins expire after 12 hours, so resident B logs in again.
        $headersB = $this->token(User::where('name', 'Resident B')->first());
        $list = $this->getJson('/api/requests', $headersB)->assertOk()->json();
        $expired = collect($list)->firstWhere('request_id', $requestB);
        $this->assertSame('cancelled', $expired['status']);
        $this->assertNull($expired['cancelled_by']);
        $this->assertDatabaseHas('notifications', ['request_id' => $requestB, 'type' => 'cancelled']);

        // One request can be opened on its own page, but only by its owner (or staff).
        $this->getJson("/api/requests/{$requestB}", $headersB)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->getJson("/api/requests/{$requestB}", $this->token(User::where('name', 'Resident A')->first()))->assertNotFound();
        $this->getJson("/api/requests/{$requestB}", $this->token($staff))->assertOk();
        $this->assertNotNull($expired['cancelled_at']);
        $this->assertDatabaseHas('notifications', ['request_id' => $requestB, 'message' => "BulanBotikaCare: Your medicine request #{$requestB} was cancelled because it was not claimed within 7 days of approval. You may submit a new request anytime."]);
        $this->getJson('/api/medicines', $this->token($staff))->assertJsonPath('0.free_stock', 10);
    }

    public function test_forecasting_techniques(): void
    {
        $series = [10, 20, 30, 40];
        $this->assertEqualsWithDelta(30.0, ForecastService::movingAverage($series), 0.001);
        $this->assertEqualsWithDelta(50.0, ForecastService::linearRegression($series), 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::exponentialSmoothing($series), 0.01);
    }
}
