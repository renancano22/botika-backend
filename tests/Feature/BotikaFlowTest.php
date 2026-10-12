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

        $requestId = $this->postJson('/api/requests', ['request_type' => 'medicine', 'note' => 'For my fever, as advised by the RHU nurse.', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 10]]], $residentHeaders)
            ->assertCreated()->assertJsonPath('resident_note', 'For my fever, as advised by the RHU nurse.')->json('request_id');

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
        $this->assertDatabaseCount('notifications', 3); // submitted (in-app) + approved + dispensed SMS
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
        $this->postJson('/api/notifications/announce', ['message' => 'Closed on Monday.', 'category' => 'closure'], $this->token($admin))->assertOk();
        $this->assertDatabaseHas('notifications', ['type' => 'closure', 'message' => 'BulanBotikaCare (Pharmacy Closure): Closed on Monday.']);
        $this->postJson('/api/notifications/announce', ['message' => 'x', 'category' => 'party'], $this->token($admin))->assertStatus(422);

        $list = $this->getJson('/api/notifications', $headersA)->assertOk()->assertJsonCount(2)->json();
        $this->assertNull($list[0]['read_at']);
        $this->getJson('/api/notifications/unread-count', $headersA)->assertJsonPath('unread', 2);

        // Opening a notification shows it and marks it as read.
        $this->getJson("/api/notifications/{$list[0]['notification_id']}", $headersA)->assertOk()
            ->assertJsonPath('notification.notification_id', $list[0]['notification_id'])
            ->assertJsonPath('request', null);
        $this->getJson('/api/notifications/unread-count', $headersA)->assertJsonPath('unread', 1);
        $this->getJson("/api/notifications/{$list[0]['notification_id']}", $headersB)->assertNotFound();

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

        // Asking for more than the limit gives a clear message.
        $this->postJson('/api/requests', ['request_type' => 'restock', ...$item(50000)], $headersB)
            ->assertStatus(422)->assertJsonPath('errors', ['items.0.quantity' => ['You can request at most 30 per medicine.']]);

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

        // Submitting and cancelling create in-app confirmations (no SMS).
        $this->assertDatabaseHas('notifications', ['request_id' => $requestA, 'type' => 'submitted', 'channel' => 'app']);
        $this->assertDatabaseHas('notifications', ['request_id' => $requestA, 'type' => 'cancelled', 'channel' => 'app']);

        // B's approved request: a reminder one day before the deadline, then it expires.
        $requestB = $this->postJson('/api/requests', ['request_type' => 'medicine', ...$item(6)], $headersB)->assertCreated()->json('request_id');
        $this->postJson("/api/requests/{$requestB}/approve", [], $this->token($staff))->assertOk();
        $days = config('botika.unclaimed_days');

        $this->travel($days - 1)->days();
        $this->getJson('/api/medicines', $this->token($staff))->assertOk();
        $this->assertDatabaseHas('notifications', ['request_id' => $requestB, 'type' => 'reminder']);
        $this->assertDatabaseHas('requests', ['request_id' => $requestB, 'status' => 'approved']);
        $this->travel(15)->minutes();
        $this->getJson('/api/medicines', $this->token($staff))->assertOk();
        $this->assertSame(1, \App\Models\Notification::where('request_id', $requestB)->where('type', 'reminder')->count()); // sent once

        $this->travel(2)->days();
        // Logins expire after 12 hours, so resident B logs in again.
        $headersB = $this->token(User::where('name', 'Resident B')->first());
        $list = $this->getJson('/api/requests', $headersB)->assertOk()->json();
        $expired = collect($list)->firstWhere('request_id', $requestB);
        $this->assertSame('expired', $expired['status']);
        $this->assertNull($expired['cancelled_by']);
        $this->assertNotNull($expired['cancelled_at']);
        $this->assertDatabaseHas('notifications', ['request_id' => $requestB, 'type' => 'expired']);

        // One request can be opened on its own page, but only by its owner (or staff).
        $this->getJson("/api/requests/{$requestB}", $headersB)->assertOk()->assertJsonPath('status', 'expired');
        $this->getJson("/api/requests/{$requestB}", $this->token(User::where('name', 'Resident A')->first()))->assertNotFound();
        $this->getJson("/api/requests/{$requestB}", $this->token($staff))->assertOk();
        $this->getJson('/api/medicines', $this->token($staff))->assertJsonPath('0.free_stock', 10);
    }

    public function test_admin_and_staff_are_warned_one_month_before_expiry(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Cefalexin 500mg', 'category' => 'Antibiotic', 'unit' => 'capsule', 'reorder_level' => 0]);
        $soon = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 20, 'expiration_date' => now()->addMonthNoOverflow()->toDateString()]);
        $later = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 30, 'expiration_date' => now()->addMonthNoOverflow()->addDays(2)->toDateString()]);

        $alerts = $this->getJson('/api/inventory/alerts', $this->token($staff))->assertOk()->json();
        $this->assertSame([$soon->inventory_id], collect($alerts['near_expiry'])->pluck('inventory_id')->all());

        $this->getJson('/api/inventory', $this->token($staff))->assertOk()
            ->assertJsonPath('0.expiry_status', 'near_expiry')
            ->assertJsonPath('1.expiry_status', 'ok');
        $this->assertSame($later->inventory_id, $this->getJson('/api/inventory', $this->token($staff))->json('1.inventory_id'));

        // Residents cannot see the stock alerts.
        $resident = $this->postJson('/api/register', [
            'name' => 'Luz Bautista', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'contact_no' => '09230000001',
        ])->assertCreated();
        $this->getJson('/api/inventory/alerts', ['Authorization' => 'Bearer ' . $resident->json('token')])->assertForbidden();
    }

    public function test_prescription_medicine_needs_uploaded_and_checked_prescription(): void
    {
        $staff = User::factory()->create();
        $rx = Medicine::create(['medicine_name' => 'Amoxicillin 500mg', 'category' => 'Antibiotic', 'unit' => 'capsule', 'reorder_level' => 0, 'requires_prescription' => true]);
        $otc = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 0]);
        foreach ([$rx, $otc] as $m) {
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        }
        $reg = $this->postJson('/api/register', [
            'name' => 'Nena Flores', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Gate', 'contact_no' => '09240000001',
        ])->assertCreated();
        $headers = ['Authorization' => 'Bearer ' . $reg->json('token')];
        $qr = $reg->json('user.resident.qr_code');

        $this->getJson('/api/medicines', $headers)->assertJsonPath('0.medicine_name', 'Amoxicillin 500mg')->assertJsonPath('0.requires_prescription', true);

        // Without a prescription the request is refused; non-prescription medicine needs none.
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $rx->medicine_id, 'quantity' => 10]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['prescription']);
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $otc->medicine_id, 'quantity' => 5]]], $headers)
            ->assertCreated();
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'prescription' => ['name' => 'rx.txt', 'data' => 'data:text/plain;base64,aGVsbG8='],
            'items' => [['medicine_id' => $rx->medicine_id, 'quantity' => 10]]], $headers)->assertStatus(422);

        $file = 'data:image/jpeg;base64,' . base64_encode('fake-photo-of-prescription');
        $id = $this->postJson('/api/requests', ['request_type' => 'medicine', 'prescription' => ['name' => 'Prescription.jpg', 'data' => $file],
            'items' => [['medicine_id' => $rx->medicine_id, 'quantity' => 10]]], $headers)->assertCreated()->json('request_id');

        $this->getJson("/api/requests/{$id}", $headers)->assertJsonPath('prescription.file_name', 'Prescription.jpg')
            ->assertJsonMissingPath('prescription.file_data');
        $this->getJson("/api/requests/{$id}/prescription", $this->token($staff))->assertOk()->assertJsonPath('data', $file);

        // Staff must confirm they checked the prescription before approving.
        $this->postJson("/api/requests/{$id}/approve", [], $this->token($staff))->assertStatus(422)->assertJsonValidationErrors(['prescription_verified']);
        $this->postJson("/api/requests/{$id}/approve", ['prescription_verified' => true], $this->token($staff))->assertOk()
            ->assertJsonPath('prescription_verifier.name', $staff->name);

        // ...and confirm they saw the original prescription when releasing the medicine.
        $this->postJson('/api/dispensing', ['request_id' => $id, 'qr_code' => $qr], $this->token($staff))->assertStatus(422);
        $this->postJson('/api/dispensing', ['request_id' => $id, 'qr_code' => $qr, 'prescription_checked' => true], $this->token($staff))->assertCreated();
        $this->assertDatabaseHas('dispensing', ['request_id' => $id, 'prescription_checked' => true]);

        // Walk-in dispensing of prescription medicine needs the same confirmation.
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => $qr, 'items' => [['medicine_id' => $rx->medicine_id, 'quantity' => 2]]], $this->token($staff))->assertStatus(422);
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => $qr, 'prescription_checked' => true, 'items' => [['medicine_id' => $rx->medicine_id, 'quantity' => 2]]], $this->token($staff))->assertCreated();
    }

    public function test_approved_requests_are_listed_for_pickup_with_counts(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $reg = $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated();
        $headers = ['Authorization' => 'Bearer ' . $reg->json('token')];

        $other = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $other->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $first = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 2]]], $headers)->json('request_id');
        // The same medicine again while #first is open: the resident is sent back to edit that request.
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 3]]], $headers)
            ->assertStatus(422)->assertJsonPath('errors.open_request_id.0', (string) $first)
            ->assertJsonPath('errors.items.0', "You already requested Paracetamol 500mg in request #{$first}. Please edit that request instead of making a new one.");
        $this->putJson("/api/requests/{$first}", ['items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 5]]], $headers)->assertOk()
            ->assertJsonPath('items.0.quantity', 5);
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $other->medicine_id, 'quantity' => 3]]], $headers)->assertCreated();
        $this->postJson("/api/requests/{$first}/approve", [], $this->token($staff))->assertOk();

        $this->getJson('/api/requests/counts?type=medicine', $headers)->assertForbidden(); // residents cannot
        $this->getJson('/api/requests/counts?type=medicine', $this->token($staff))->assertOk()
            ->assertJsonPath('pending', 1)->assertJsonPath('approved', 1)->assertJsonPath('all', 2);

        $this->getJson('/api/dashboard', $this->token($staff))->assertOk()
            ->assertJsonCount(1, 'ready_for_pickup')
            ->assertJsonPath('ready_for_pickup.0.request_id', $first)
            ->assertJsonPath('ready_for_pickup.0.resident.name', 'Maria Santos')
            ->assertJsonPath('ready_for_pickup.0.resident.contact_no', '09171234567')
            ->assertJsonPath('ready_for_pickup.0.claim_by', \App\Models\MedicineRequest::claimDeadline(now())->toIso8601String());

        // The request details route still works next to /requests/counts.
        $this->getJson("/api/requests/{$first}", $this->token($staff))->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_monthly_limit_of_100_and_walk_in_limit_of_30(): void
    {
        $staff = User::factory()->create();
        $a = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $b = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        foreach ([$a, $b] as $m) {
            Inventory::create(['medicine_id' => $m->medicine_id, 'quantity' => 300, 'expiration_date' => now()->addYear()->toDateString()]);
        }
        $reg = $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated();
        $qr = $reg->json('user.resident.qr_code');
        $resident = User::where('email', 'maria@gmail.com')->firstOrFail();
        $walkIn = fn (array $items) => $this->postJson('/api/dispensing/walk-in', ['qr_code' => $qr, 'items' => $items], $this->token($staff));
        $ask = fn (int $medicineId, int $qty) => $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicineId, 'quantity' => $qty]]], $this->token($resident));

        // Walk-in: at most 30 per medicine.
        $walkIn([['medicine_id' => $a->medicine_id, 'quantity' => 31]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $walkIn([['medicine_id' => $a->medicine_id, 'quantity' => 30], ['medicine_id' => $b->medicine_id, 'quantity' => 30]])->assertCreated(); // 60 received

        // Online requests count too: 60 received + 30 pending = 90, so only 10 more.
        $first = $ask($a->medicine_id, 30)->assertCreated()->json('request_id');
        $ask($b->medicine_id, 20)->assertStatus(422)->assertJsonValidationErrors('monthly_limit');
        $second = $ask($b->medicine_id, 10)->assertCreated()->json('request_id');
        $this->getJson('/api/monthly-limit', $this->token($resident))->assertOk()
            ->assertJsonPath('limit', 100)->assertJsonPath('claimed', 60)->assertJsonPath('pending', 40)->assertJsonPath('remaining', 0);

        // Approving sets the medicine aside; a walk-in cannot go over the limit either.
        $this->postJson("/api/requests/{$first}/approve", [], $this->token($staff))->assertOk();       // 60 + 30 = 90
        $walkIn([['medicine_id' => $b->medicine_id, 'quantity' => 15]])->assertStatus(422)->assertJsonValidationErrors('monthly_limit');
        $walkIn([['medicine_id' => $b->medicine_id, 'quantity' => 10]])->assertCreated();               // 70 received + 30 set aside = 100
        $this->postJson("/api/requests/{$second}/approve", [], $this->token($staff))->assertStatus(422)->assertJsonValidationErrors('monthly_limit');
        $this->getJson('/api/residents/lookup?code=' . $qr, $this->token($staff))->assertOk()
            ->assertJsonPath('monthly.claimed', 70)->assertJsonPath('monthly.set_aside', 30)->assertJsonPath('monthly.remaining', 0);
        $this->postJson('/api/dispensing', ['request_id' => $first, 'qr_code' => $qr], $this->token($staff))->assertCreated(); // exactly 100

        // Next month the limit starts again (the pending request still counts as asked for).
        $this->travelTo(now()->startOfMonth()->addMonthNoOverflow()->addDay());
        $this->getJson('/api/monthly-limit', $this->token($resident))->assertOk()
            ->assertJsonPath('claimed', 0)->assertJsonPath('pending', 10)->assertJsonPath('remaining', 90);
        $this->postJson("/api/requests/{$second}/approve", [], $this->token($staff))->assertOk();
    }

    public function test_one_open_restock_request_per_medicine(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensive', 'unit' => 'tablet', 'reorder_level' => 5]);
        $b = Medicine::create(['medicine_name' => 'Metformin 500mg', 'category' => 'Antidiabetic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $headers = ['Authorization' => 'Bearer ' . $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated()->json('token')];
        $restock = fn (int $id) => $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $id, 'quantity' => 10]]], $headers);

        $first = $restock($a->medicine_id)->assertCreated()->json('request_id');
        $restock($a->medicine_id)->assertStatus(422)->assertJsonValidationErrors('items')
            ->assertJsonFragment(['items' => ["You already submitted a restock request for Losartan 50mg (request #{$first}). We will notify you when the medicine is available."]]);
        $restock($b->medicine_id)->assertCreated(); // a different medicine is fine

        // Still blocked while approved and waiting for stock; allowed again once it is closed.
        $this->postJson("/api/requests/{$first}/approve", [], $this->token($admin))->assertOk();
        $restock($a->medicine_id)->assertStatus(422);
        $this->postJson("/api/requests/{$first}/cancel", [], $headers)->assertOk();
        $restock($a->medicine_id)->assertCreated();
    }

    public function test_forecasting_techniques(): void
    {
        $series = [10, 20, 30, 40];
        $this->assertEqualsWithDelta(30.0, ForecastService::movingAverage($series), 0.001);
        $this->assertEqualsWithDelta(50.0, ForecastService::linearRegression($series), 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::exponentialSmoothing($series), 0.01);

        // Several months ahead: moving average uses its own forecasts, the trend line keeps going, smoothing stays level.
        $ma = ForecastService::forecastSeries('moving_average', $series, 2);
        $this->assertEqualsWithDelta(30.0, $ma[0], 0.001);
        $this->assertEqualsWithDelta(33.33, $ma[1], 0.01);
        $this->assertEqualsWithDelta(60.0, ForecastService::forecastSeries('linear_regression', $series, 2)[1], 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::forecastSeries('exponential_smoothing', $series, 3)[2], 0.01);
        $this->assertSame([0.0, 0.0], ForecastService::forecastSeries('linear_regression', [], 2));
    }

    public function test_demand_forecasting_page_data_and_saving(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $ceti = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 10, 'expiration_date' => now()->addYear()->toDateString()]);
        Inventory::create(['medicine_id' => $ceti->medicine_id, 'quantity' => 100, 'expiration_date' => now()->addYear()->toDateString()]);

        $qr1 = $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated()->json('user.resident.qr_code');
        $qr2 = $this->postJson('/api/register', [
            'name' => 'Jose Reyes', 'email' => 'jose@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Bical', 'contact_no' => '09181234567',
        ])->assertCreated()->json('user.resident.qr_code');
        $maria = \App\Models\Resident::where('qr_code', $qr1)->firstOrFail();
        $jose = \App\Models\Resident::where('qr_code', $qr2)->firstOrFail();

        // No records yet: nothing to learn from, everything is 0.
        $this->getJson('/api/forecasts?months=6', $this->token($admin))->assertOk()
            ->assertJsonPath('data_start', null)->assertJsonPath('months_with_data', 0);

        // 10 tablets of paracetamol dispensed to Maria in each of the last 6 months (this month included).
        for ($k = 0; $k <= 5; $k++) {
            $date = now()->startOfMonth()->subMonthsNoOverflow($k);
            $req = \App\Models\MedicineRequest::create(['resident_id' => $maria->resident_id, 'request_type' => 'medicine', 'status' => 'dispensed', 'request_date' => $date, 'reviewed_at' => $date]);
            $req->items()->create(['medicine_id' => $para->medicine_id, 'quantity' => 10]);
            $d = \App\Models\Dispensing::create(['request_id' => $req->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => $date]);
            \App\Models\DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $para->medicine_id, 'quantity' => 10]);
        }
        // A pending request counts as "requested" but not as "approved" for the barangay ranking.
        $pending = \App\Models\MedicineRequest::create(['resident_id' => $jose->resident_id, 'request_type' => 'medicine', 'status' => 'pending', 'request_date' => now()]);
        $pending->items()->create(['medicine_id' => $ceti->medicine_id, 'quantity' => 2]);

        $this->getJson('/api/forecasts', $this->token($staff))->assertForbidden();
        $this->getJson('/api/forecasts?horizon=2', $this->token($admin))->assertStatus(422);

        $res = $this->getJson('/api/forecasts?months=6&horizon=3&method=moving_average', $this->token($admin))->assertOk()
            ->assertJsonPath('method', 'moving_average')
            ->assertJsonCount(6, 'history_keys')
            ->assertJsonCount(3, 'forecast_keys')
            ->assertJsonPath('forecast_keys.0', now()->startOfMonth()->addMonthNoOverflow()->format('Y-m'))
            ->assertJsonPath('history_keys.5', now()->format('Y-m'))
            ->assertJsonPath('data_start', now()->startOfMonth()->subMonthsNoOverflow(5)->format('Y-m'))
            ->assertJsonPath('months_with_data', 6)
            ->assertJsonPath('summary.medicines_analyzed', 2)
            ->assertJsonPath('summary.potential_stockouts', 1)
            ->assertJsonPath('summary.restock_recommendations', 1)
            ->assertJsonPath('top_barangays.0.barangay', 'Zone I Poblacion')
            ->assertJsonPath('top_barangays.0.total', 6)
            ->assertJsonCount(1, 'top_barangays')
            ->assertJsonPath('top_medicines.0.medicine_name', 'Paracetamol 500mg')
            ->assertJsonPath('top_medicines.0.total', 6)
            ->assertJsonPath('top_medicines.1.medicine_name', 'Cetirizine 10mg');

        $rows = collect($res->json('results'))->keyBy('medicine_name');
        $p = $rows['Paracetamol 500mg'];
        $this->assertSame(30, $p['predicted_demand']);            // 10 a month for 3 months
        $this->assertSame(25, $p['recommended_restock']);         // 30 + reorder level 5 - 10 in stock
        $this->assertSame('high', $p['priority']);                // stock runs out within the period
        $this->assertCount(3, $p['forecast']);
        $this->assertEqualsWithDelta(10, $p['history'][now()->format('Y-m')], 0.001); // this month counts too

        // A longer window does not treat the months before the first record as "zero demand".
        $long = collect($this->getJson('/api/forecasts?months=12&horizon=3&method=linear_regression', $this->token($admin))
            ->assertOk()->assertJsonPath('months_with_data', 6)->json('results'))->keyBy('medicine_name');
        $this->assertSame(30, $long['Paracetamol 500mg']['predicted_demand']);
        $c = $rows['Cetirizine 10mg'];
        $this->assertSame(0, $c['recommended_restock']);
        $this->assertNull($c['priority']);

        // No method chosen: the most accurate one is used.
        $auto = $this->getJson('/api/forecasts?months=6', $this->token($admin))->assertOk()->json();
        $this->assertSame($auto['best_method'], $auto['method']);
        $this->assertSame(3, $auto['horizon']);

        // Generate Forecast saves every method's monthly forecast; running it again updates the same rows.
        $this->postJson('/api/forecasts/generate', ['months' => 6, 'horizon' => 3], $this->token($staff))->assertForbidden();
        $this->postJson('/api/forecasts/generate', ['months' => 6, 'horizon' => 3], $this->token($admin))->assertOk()->assertJsonPath('saved', true);
        $this->postJson('/api/forecasts/generate', ['months' => 6, 'horizon' => 3], $this->token($admin))->assertOk();
        $this->assertSame(2 * 3 * 3, \App\Models\Forecast::count());
        $this->assertEqualsWithDelta(10, \App\Models\Forecast::where('medicine_id', $para->medicine_id)->where('method', 'linear_regression')->value('predicted_demand'), 0.01);
    }
}
