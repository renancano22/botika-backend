<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\ForecastService;
use App\Support\MedicineCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        // New accounts get a temporary password made by the system; the administrator cannot choose one.
        $newStaff = ['name' => 'Rosa Lim', 'email' => 'rosa.lim@gmail.com', 'role' => 'staff'];
        $this->postJson('/api/users', $newStaff + ['password' => 'secret123', 'password_confirmation' => 'secret123'], $this->token($admin))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $newStaff, $this->token($admin))->assertCreated()->assertJsonStructure(['user', 'temporary_password']);

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

        // Edit information (the Resident ID stays the same).
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

        // Edit Profile "Save Changes": information, optional password and picture in one request.
        $info = ['name' => 'Ana Dela Cruz', 'email' => 'ana@gmail.com', 'contact_no' => '09197654321', 'barangay' => 'Zone II Poblacion', 'address_line' => ''];
        // Blank password fields: the information is saved and the password is left as it is.
        $this->putJson('/api/profile', array_merge($info, ['name' => 'Ana  B. Cruz', 'address_line' => ' <b>Purok 5</b> ',
            'current_password' => '', 'password' => '', 'password_confirmation' => '']), $headers)->assertOk()
            ->assertJsonPath('user.name', 'Ana B. Cruz')
            ->assertJsonPath('address_line', 'Purok 5')
            ->assertJsonPath('password_changed', false);
        $this->postJson('/api/login', ['login' => 'ana@gmail.com', 'password' => 'newpass123'])->assertOk();

        // Wrong current password, missing current password, mismatch or weak password: nothing is saved.
        $this->putJson('/api/profile', array_merge($info, ['name' => 'Changed Name', 'current_password' => 'wrong123', 'password' => 'another123', 'password_confirmation' => 'another123']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->putJson('/api/profile', array_merge($info, ['password' => 'another123', 'password_confirmation' => 'another123']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->putJson('/api/profile', array_merge($info, ['current_password' => 'newpass123', 'password' => 'another123', 'password_confirmation' => 'another999']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->putJson('/api/profile', array_merge($info, ['current_password' => 'newpass123', 'password' => 'short', 'password_confirmation' => 'short']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->putJson('/api/profile', array_merge($info, ['current_password' => 'newpass123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['password']); // must be a new password
        $this->getJson('/api/profile', $headers)->assertJsonPath('user.name', 'Ana B. Cruz');

        // Correct current password: information, new password and new picture are saved together.
        $this->putJson('/api/profile', array_merge($info, ['current_password' => 'newpass123', 'password' => 'another123', 'password_confirmation' => 'another123', 'photo' => $photo]), $headers)
            ->assertOk()
            ->assertJsonPath('password_changed', true)
            ->assertJsonPath('user.name', 'Ana Dela Cruz')
            ->assertJsonPath('user.resident.photo', $photo);
        $this->postJson('/api/login', ['login' => 'ana@gmail.com', 'password' => 'another123'])->assertOk();
        $this->putJson('/api/profile', array_merge($info, ['remove_photo' => true]), $headers)->assertOk()->assertJsonPath('user.resident.photo', null);
        $this->putJson('/api/profile', array_merge($info, ['photo' => 'data:text/html;base64,PGI+']), $headers)->assertStatus(422)->assertJsonValidationErrors(['photo']);

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
            ->assertJsonPath('errors.items.0', "You already requested Paracetamol 500mg in request No. {$first}. Please edit that request instead of making a new one.");
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

    public function test_walk_in_limit_of_30_and_no_monthly_limit(): void
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
        $walkIn([['medicine_id' => $a->medicine_id, 'quantity' => 30], ['medicine_id' => $b->medicine_id, 'quantity' => 30]])->assertCreated();
        $walkIn([['medicine_id' => $a->medicine_id, 'quantity' => 30], ['medicine_id' => $b->medicine_id, 'quantity' => 30]])->assertCreated(); // 120 this month

        // No monthly limit: requests and approvals are not limited by what was received this month.
        $first = $ask($a->medicine_id, 30)->assertCreated()->json('request_id');
        $second = $ask($b->medicine_id, 30)->assertCreated()->json('request_id');
        $this->postJson("/api/requests/{$first}/approve", [], $this->token($staff))->assertOk();
        $this->postJson("/api/requests/{$second}/approve", [], $this->token($staff))->assertOk();
        $this->postJson('/api/dispensing', ['request_id' => $first, 'qr_code' => $qr], $this->token($staff))->assertCreated();
        $this->getJson('/api/monthly-limit', $this->token($resident))->assertNotFound();
        $this->getJson('/api/residents/lookup?code=' . $qr, $this->token($staff))->assertOk()->assertJsonMissingPath('monthly');
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
            ->assertJsonFragment(['items' => ["You already submitted a restock request for Losartan 50mg (request No. {$first}). We will notify you when the medicine is available."]]);
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

        // Several months ahead: the moving average and smoothing stay level, the trend line keeps going.
        $this->assertSame([30.0, 30.0], ForecastService::forecastSeries('moving_average', $series, 2));
        $this->assertEqualsWithDelta(60.0, ForecastService::forecastSeries('linear_regression', $series, 2)[1], 0.001);
        $this->assertEqualsWithDelta(24.67, ForecastService::forecastSeries('exponential_smoothing', $series, 3)[2], 0.01);

        // Not enough months: no made-up numbers. A trend line needs at least 3 months.
        $this->assertNull(ForecastService::forecastSeries('linear_regression', [], 2));
        $this->assertNull(ForecastService::forecastSeries('linear_regression', [17], 2));
        $this->assertNull(ForecastService::forecastSeries('linear_regression', [17, 20], 2));
        $this->assertNull(ForecastService::forecastSeries('moving_average', [], 2));
        // One month: the moving average and smoothing can only repeat that month (a provisional estimate).
        $this->assertSame([17.0, 17.0], ForecastService::forecastSeries('moving_average', [17], 2));
        $this->assertSame([17.0, 17.0], ForecastService::forecastSeries('exponential_smoothing', [17], 2));

        // The numbers behind each forecast.
        $ma = ForecastService::calculation('moving_average', ['2026-07' => 5, '2026-08' => 10, '2026-09' => 20, '2026-10' => 30], 1);
        $this->assertSame(3, $ma['window']);
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $ma['months']);
        $this->assertEqualsWithDelta(20, $ma['average'], 0.001);
        $lr = ForecastService::calculation('linear_regression', ['a' => 30, 'b' => 20, 'c' => 10], 3);
        $this->assertSame('down', $lr['trend']);
        $this->assertEqualsWithDelta(-10, $lr['slope'], 0.001);
        $this->assertTrue($lr['below_zero']);
        $this->assertSame([0.0, 0.0, 0.0], ForecastService::forecastSeries('linear_regression', [30, 20, 10], 3)); // never below 0
        $this->assertSame('flat', ForecastService::calculation('linear_regression', [10, 10, 10], 1)['trend']);
        $es = ForecastService::calculation('exponential_smoothing', ['2026-08' => 10, '2026-09' => 20], 1);
        $this->assertEqualsWithDelta(13, $es['level'], 0.001); // 0.3 x 20 + 0.7 x 10
        $this->assertCount(2, $es['steps']);
    }

    public function test_forecast_with_limited_records(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $ceti = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        $qr = $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated()->json('user.resident.qr_code');
        $maria = \App\Models\Resident::where('qr_code', $qr)->firstOrFail();
        $give = function (Carbon $date, int $qty) use ($maria, $staff, $para) {
            $req = \App\Models\MedicineRequest::create(['resident_id' => $maria->resident_id, 'request_type' => 'medicine', 'status' => 'dispensed', 'request_date' => $date, 'reviewed_at' => $date]);
            $req->items()->create(['medicine_id' => $para->medicine_id, 'quantity' => $qty]);
            $d = \App\Models\Dispensing::create(['request_id' => $req->request_id, 'dispensed_by' => $staff->user_id, 'dispensed_at' => $date]);
            \App\Models\DispensingItem::create(['dispensing_id' => $d->dispensing_id, 'medicine_id' => $para->medicine_id, 'quantity' => $qty]);
        };

        // Only this month so far (17 tablets): a provisional basis; no trend line can be drawn.
        $give(now()->startOfMonth(), 17);
        $res = $this->getJson('/api/forecasts?months=6&horizon=3&method=linear_regression', $this->token($admin))->assertOk()
            ->assertJsonPath('method', 'moving_average')               // Linear Regression is not available yet
            ->assertJsonPath('requested_method', 'linear_regression')
            ->assertJsonPath('basis.months', [now()->format('Y-m')])
            ->assertJsonPath('basis.partial', true)
            ->assertJsonPath('basis.complete_months', 0)
            ->assertJsonPath('methods_info.linear_regression.available', false)
            ->assertJsonPath('methods_info.linear_regression.min_months', 3)
            ->assertJsonPath('methods_info.moving_average.available', true)
            ->assertJsonPath('methods_info.moving_average.provisional', true)
            ->assertJsonPath('methods_info.exponential_smoothing.available', true)
            ->assertJsonPath('overall.methods.linear_regression', null);
        $rows = collect($res->json('results'))->keyBy('medicine_name');
        $this->assertSame(51, $rows['Paracetamol 500mg']['predicted_demand']); // 17 a month x 3, provisional
        $this->assertNull($rows['Paracetamol 500mg']['methods']['linear_regression']);
        $this->assertEqualsWithDelta(51, $rows['Paracetamol 500mg']['methods']['exponential_smoothing']['predicted_demand'], 0.01);
        $this->assertSame(0, $rows['Cetirizine 10mg']['predicted_demand']); // none given out since records began: a real 0

        // Nothing is saved for a method that could not forecast.
        $this->postJson('/api/forecasts/generate', ['months' => 6, 'horizon' => 3], $this->token($admin))->assertOk();
        $this->assertSame(0, \App\Models\Forecast::where('method', 'linear_regression')->count());
        $this->assertSame(2 * 3, \App\Models\Forecast::where('method', 'moving_average')->count());

        // Next month: last month is complete, so it is the basis and the new month in progress is not counted yet.
        $this->travelTo(now()->startOfMonth()->addMonthNoOverflow()->addDays(3));
        $give(now(), 2);
        $res = $this->getJson('/api/forecasts?months=6&horizon=1&method=moving_average', $this->token($admin))->assertOk()
            ->assertJsonPath('basis.months', [now()->subMonthNoOverflow()->format('Y-m')])
            ->assertJsonPath('basis.partial', false)
            ->assertJsonPath('months_with_data', 2);
        $p = collect($res->json('results'))->firstWhere('medicine_name', 'Paracetamol 500mg');
        $this->assertSame(17, $p['predicted_demand']);
        $this->assertSame(2, $p['history'][now()->format('Y-m')]); // still shown as this month so far
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

    public function test_botika_medicine_list_and_unrecorded_stock(): void
    {
        $staff = User::factory()->create();
        // Added by hand before the list: it keeps its stock and history when the list is imported.
        $old = Medicine::create(['medicine_name' => 'Amoxicillin 500mg', 'category' => 'Antibiotic', 'unit' => 'capsule', 'reorder_level' => 10]);
        Inventory::create(['medicine_id' => $old->medicine_id, 'quantity' => 30, 'expiration_date' => now()->addYear()->toDateString()]);
        $other = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic / Antipyretic', 'unit' => 'tablet', 'reorder_level' => 10]);

        $total = count(MedicineCatalog::items());
        $this->assertSame(['added' => $total - 1, 'matched' => 1], MedicineCatalog::import());
        $this->assertSame(['added' => 0, 'matched' => $total], MedicineCatalog::import()); // safe to run again

        $old->refresh();
        $this->assertSame('Amoxicillin 500mg Capsule', $old->medicine_name);
        $this->assertSame('Antibacterial', $old->category);
        $this->assertSame('Analgesic/Antipyretic', $other->fresh()->category); // not in the list: kept, category renamed
        $this->assertDatabaseHas('medicines', ['medicine_name' => 'Ferrous Sulfate + Folic Acid', 'strength' => null, 'dosage_form' => null, 'unit' => 'piece']);
        $this->assertDatabaseHas('medicines', ['medicine_name' => 'Dicycloverine 10mg Tablet', 'category' => 'Gastro']);
        $this->assertDatabaseHas('medicines', ['medicine_name' => 'Atorvastatin 80mg Tablet', 'category' => 'Antihyperlipidemia', 'requires_prescription' => true]);
        $this->assertDatabaseMissing('medicines', ['generic_name' => 'Paracetamol', 'strength' => '250mg/5ml']); // on hold
        $this->assertSame(1, Inventory::count()); // no stock, batch or expiry date is made up

        $list = collect($this->getJson('/api/medicines', $this->token($staff))->assertOk()->json())->keyBy('medicine_name');
        $this->assertSame('available', $list['Amoxicillin 500mg Capsule']['status']);
        $lactulose = $list['Lactulose Syrup'];
        $this->assertSame('unrecorded', $lactulose['status']);
        $this->assertFalse($lactulose['stock_recorded']);
        $this->assertSame('bottle', $lactulose['unit']);
        $this->assertSame([], $lactulose['batches']);
        $this->assertSame('unrecorded', $list['Paracetamol 500mg']['status']);
        $this->assertSame(['MED-' . str_pad((string) $lactulose['medicine_id'], 4, '0', STR_PAD_LEFT)], [$lactulose['medicine_code']]);
        $this->assertCount(18, array_intersect(MedicineCatalog::CATEGORIES, $this->getJson('/api/medicines/categories', $this->token($staff))->json()));

        // Residents can't request a medicine whose stock is not recorded, but can ask for a restock.
        $headers = ['Authorization' => 'Bearer ' . $this->postJson('/api/register', [
            'name' => 'Maria Santos', 'email' => 'maria@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09171234567',
        ])->assertCreated()->json('token')];
        $this->getJson('/api/medicines', $headers)->assertOk()->assertJsonFragment(['medicine_name' => 'Lactulose Syrup', 'status' => 'unrecorded', 'available_stock' => 0]);
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $lactulose['medicine_id'], 'quantity' => 1]]], $headers)->assertStatus(422);
        $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $lactulose['medicine_id'], 'quantity' => 1]]], $headers)->assertCreated();

        // Staff confirm there is none (out of stock) or record a stock-in.
        $this->postJson("/api/medicines/{$lactulose['medicine_id']}/confirm-no-stock", [], $this->token($staff))->assertOk();
        $this->postJson("/api/medicines/{$old->medicine_id}/confirm-no-stock", [], $this->token($staff))->assertStatus(422);
        $budesonide = Medicine::where('medicine_name', 'Budesonide Nebule')->firstOrFail();
        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $budesonide->medicine_id, 'quantity' => 50,
            'expiration_date' => now()->addYear()->toDateString()], $this->token($staff))->assertCreated();
        $list = collect($this->getJson('/api/medicines', $this->token($staff))->json())->keyBy('medicine_name');
        $this->assertSame('out_of_stock', $list['Lactulose Syrup']['status']);
        $this->assertSame('available', $list['Budesonide Nebule']['status']);
        $this->assertSame(50, $list['Budesonide Nebule']['batches'][0]['quantity']);
        $this->assertNotNull($list['Budesonide Nebule']['last_updated']);
        $this->assertGreaterThan(0, $this->getJson('/api/dashboard', $this->token($staff))->json('counts.unrecorded'));

        // Add / edit: name = generic name + strength + dosage form, no duplicates.
        $this->postJson('/api/medicines', ['generic_name' => 'Irbesartan', 'strength' => '150mg', 'category' => 'Antihypertensives', 'unit' => 'tablet', 'reorder_level' => 20], $this->token($staff))
            ->assertStatus(422)->assertJsonValidationErrors('generic_name');
        $this->postJson('/api/medicines', ['generic_name' => 'Zinc', 'dosage_form' => 'Powder', 'category' => 'Vitamins/Supplements', 'unit' => 'sachet', 'reorder_level' => 20], $this->token($staff))
            ->assertStatus(422)->assertJsonValidationErrors('dosage_form');
        $irbesartan = Medicine::where('medicine_name', 'Irbesartan 150mg')->firstOrFail();
        $this->putJson("/api/medicines/{$irbesartan->medicine_id}", ['generic_name' => 'Irbesartan', 'strength' => '150mg', 'dosage_form' => 'Tablet',
            'category' => 'Antihypertensives', 'unit' => 'tablet', 'reorder_level' => 20, 'requires_prescription' => true], $this->token($staff))
            ->assertOk()->assertJsonPath('medicine_name', 'Irbesartan 150mg Tablet');
    }

    public function test_staff_find_a_resident_by_name_and_must_verify_identity_before_dispensing(): void
    {
        $staff = User::factory()->create();
        $register = fn (string $name, string $email, string $barangay, string $mobile) => $this->postJson('/api/register', [
            'name' => $name, 'email' => $email, 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => $barangay, 'contact_no' => $mobile,
        ])->assertCreated();
        $juan = $register('Juan Dela Cruz', 'juan@gmail.com', 'Zone I Poblacion', '09171112222');
        $register('Juan Dela Cruz', 'juan2@gmail.com', 'Zone II Poblacion', '09173334444'); // same name, other barangay
        $register('Ana Reyes', 'ana@gmail.com', 'Zone I Poblacion', '09175556666');
        $residentId = $juan->json('user.resident.resident_id');

        // Only staff can search, and the results carry no mobile number or address.
        $this->getJson('/api/residents/search?name=juan', ['Authorization' => 'Bearer ' . $juan->json('token')])->assertForbidden();
        $res = $this->getJson('/api/residents/search?name=juan%20cruz', $this->token($staff))->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(['resident_id', 'name', 'qr_code', 'barangay'], array_keys($res->json('results.0')));
        $this->getJson('/api/residents/search?name=juan&barangay=Zone%20I%20Poblacion', $this->token($staff))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('results.0.qr_code', 'BBC-000001');
        $this->getJson('/api/residents/search?name=pedro', $this->token($staff))->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/residents/search?name=j', $this->token($staff))->assertStatus(422);
        $this->getJson('/api/residents/search?name=' . urlencode('%_%'), $this->token($staff))->assertStatus(422);

        // Many searches while typing do not block confirming the identity afterwards.
        foreach (range(1, 15) as $i) {
            $this->getJson('/api/residents/search?name=juan', $this->token($staff))->assertOk();
        }

        // Picking a name is not enough: the staff must confirm they checked the resident's identity.
        $this->postJson("/api/residents/{$residentId}/verify", [], $this->token($staff))
            ->assertStatus(422)->assertJsonValidationErrors('identity_confirmed');
        $this->postJson("/api/residents/{$residentId}/verify", ['identity_confirmed' => true], ['Authorization' => 'Bearer ' . $juan->json('token')])
            ->assertForbidden();
        $this->postJson("/api/residents/{$residentId}/verify", ['identity_confirmed' => true], $this->token($staff))
            ->assertOk()->assertJsonPath('resident.qr_code', 'BBC-000001')->assertJsonStructure(['approved_requests', 'dispensing_history']);
    }

    public function test_a_double_submitted_stock_in_is_saved_only_once(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Losartan 50mg', 'category' => 'Antihypertensives', 'unit' => 'tablet', 'reorder_level' => 20]);
        $stockIn = ['medicine_id' => $medicine->medicine_id, 'quantity' => 305, 'expiration_date' => now()->addYear()->toDateString()];

        $first = $this->postJson('/api/inventory/stock-in', $stockIn, $this->token($staff))->assertCreated();
        $this->postJson('/api/inventory/stock-in', $stockIn, $this->token($staff))
            ->assertStatus(422)->assertJsonValidationErrors('medicine_id');
        $this->assertDatabaseCount('inventory', 1);
        $this->assertDatabaseCount('stock_transactions', 1);
        $this->assertSame($first->json('inventory_id'), Inventory::first()->inventory_id);

        // A different delivery (other expiration date) is still recorded right away.
        $this->postJson('/api/inventory/stock-in', [...$stockIn, 'expiration_date' => now()->addYears(2)->toDateString()], $this->token($staff))->assertCreated();
        // The same delivery entered again later is allowed (e.g. a second box received afterwards).
        $this->travel(1)->minutes();
        $this->postJson('/api/inventory/stock-in', $stockIn, $this->token($staff))->assertCreated();
        $this->assertDatabaseCount('inventory', 3);
    }

    public function test_staff_register_a_walk_in_resident_without_cellphone_and_dispense_with_stock_out(): void
    {
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $batch = Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $walkIn = ['name' => 'Lola  Remedios Bañez', 'barangay' => 'Zone I Poblacion', 'address_line' => 'Purok 2'];

        // Residents cannot register other residents.
        $resident = $this->postJson('/api/register', ['name' => 'Ana Reyes', 'email' => 'ana@gmail.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09175556666'])->assertCreated();
        $this->postJson('/api/residents', $walkIn, ['Authorization' => 'Bearer ' . $resident->json('token')])->assertForbidden();

        // No mobile number: still registered, with a Resident ID and no login account.
        $res = $this->postJson('/api/residents', $walkIn, $this->token($staff))->assertCreated()
            ->assertJsonPath('resident.name', 'Lola Remedios Bañez')
            ->assertJsonPath('resident.contact_no', null)
            ->assertJsonPath('resident.address', 'Purok 2, Zone I Poblacion, Bulan, Sorsogon');
        $qr = $res->json('resident.qr_code');
        $this->assertDatabaseHas('residents', ['qr_code' => $qr, 'user_id' => null]);

        // The same name in the same barangay is never registered twice (not even when confirmed).
        $this->postJson('/api/residents', [...$walkIn, 'name' => 'lola remedios  bañez'], $this->token($staff))
            ->assertStatus(409)->assertJsonPath('blocked', true)->assertJsonPath('matches.0.qr_code', $qr);
        $this->postJson('/api/residents', [...$walkIn, 'confirm_new' => true], $this->token($staff))->assertStatus(409);
        // A similar name (middle initial) is shown first; a different person can be registered after checking.
        $similar = [...$walkIn, 'name' => 'Lola Remedios C. Bañez'];
        $this->postJson('/api/residents', $similar, $this->token($staff))->assertStatus(409)->assertJsonPath('blocked', false);
        $this->postJson('/api/residents', [...$similar, 'confirm_new' => true], $this->token($staff))->assertCreated();
        // The same name in another barangay is a different resident.
        $this->postJson('/api/residents', [...$walkIn, 'barangay' => 'Zone II Poblacion'], $this->token($staff))->assertCreated();
        // A mobile number already used by another resident is refused.
        $this->postJson('/api/residents', ['name' => 'Pedro Cruz', 'barangay' => 'Zone I Poblacion', 'contact_no' => '0917 555 6666'], $this->token($staff))
            ->assertStatus(422)->assertJsonValidationErrors('contact_no');

        // The medicine given is recorded and taken out of the inventory.
        $this->postJson('/api/dispensing/walk-in', ['qr_code' => $qr, 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 10]]], $this->token($staff))
            ->assertCreated();
        $this->assertSame(40, $batch->fresh()->quantity);
        $this->assertDatabaseHas('stock_transactions', ['inventory_id' => $batch->inventory_id, 'type' => 'dispensed', 'quantity' => 10]);
        // The stock history says who received it.
        $this->getJson('/api/inventory/transactions?type=dispensed', $this->token($staff))->assertOk()
            ->assertJsonPath('0.reason_text', "Walk-in: dispensed to Lola Remedios Bañez ({$qr})");
        // No cellphone: the dispensing message is kept in the log, not sent by SMS.
        $this->assertDatabaseHas('notifications', ['type' => 'dispensed', 'channel' => 'none', 'status' => 'logged']);
        // Found again later by name.
        $this->getJson('/api/residents/search?name=remedios', $this->token($staff))->assertOk()->assertJsonPath('results.0.qr_code', $qr);
    }

    public function test_forms_reject_invalid_input(): void
    {
        $staff = User::factory()->create();
        $h = $this->token($staff);
        $medicine = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesics', 'unit' => 'tablet', 'reorder_level' => 5]);
        $valid = ['generic_name' => 'Cetirizine', 'strength' => '10mg', 'dosage_form' => 'Tablet', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 20];

        // Medicine form
        $this->postJson('/api/medicines', [...$valid, 'generic_name' => '<b>x</b>'], $h)->assertStatus(422)->assertJsonValidationErrors('generic_name');
        $this->postJson('/api/medicines', [...$valid, 'strength' => 'mg10'], $h)->assertStatus(422)->assertJsonValidationErrors('strength');
        $this->postJson('/api/medicines', [...$valid, 'category' => 'Made Up Category'], $h)->assertStatus(422)->assertJsonValidationErrors('category');
        $this->postJson('/api/medicines', [...$valid, 'unit' => '123'], $h)->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->postJson('/api/medicines', [...$valid, 'reorder_level' => -1], $h)->assertStatus(422)->assertJsonValidationErrors('reorder_level');
        $this->postJson('/api/medicines', $valid, $h)->assertCreated()->assertJsonPath('medicine_name', 'Cetirizine 10mg Tablet');

        // Stock-in / stock-out
        $in = ['medicine_id' => $medicine->medicine_id, 'quantity' => 10, 'expiration_date' => now()->addYear()->toDateString()];
        $this->postJson('/api/inventory/stock-in', [...$in, 'quantity' => 0], $h)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson('/api/inventory/stock-in', [...$in, 'quantity' => 100001], $h)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson('/api/inventory/stock-in', [...$in, 'expiration_date' => now()->toDateString()], $h)->assertStatus(422)->assertJsonValidationErrors('expiration_date');
        $this->postJson('/api/inventory/stock-in', [...$in, 'expiration_date' => now()->addYears(11)->toDateString()], $h)->assertStatus(422)->assertJsonValidationErrors('expiration_date');
        $batch = $this->postJson('/api/inventory/stock-in', $in, $h)->assertCreated()->json('inventory_id');
        $this->postJson("/api/inventory/{$batch}/stock-out", ['quantity' => 11, 'reason' => 'Damaged'], $h)->assertStatus(422);
        $this->postJson("/api/inventory/{$batch}/stock-out", ['quantity' => 1, 'reason' => 'x'], $h)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->getJson('/api/inventory/transactions?from=2026-10-10&to=2026-10-01', $h)->assertStatus(422)->assertJsonValidationErrors('to');

        // Announcement
        $admin = User::factory()->admin()->create();
        $this->postJson('/api/notifications/announce', ['message' => 'Hi'], $this->token($admin))->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/api/notifications/announce', ['message' => str_repeat('a', 301)], $this->token($admin))->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_admin_manages_user_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $medicine = Medicine::create(['medicine_name' => 'Cetirizine 10mg', 'category' => 'Antihistamine', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $medicine->medicine_id, 'quantity' => 40, 'expiration_date' => now()->addYear()->toDateString()]);
        $reg = $this->postJson('/api/register', [
            'name' => 'Lito Garcia', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Zone I Poblacion', 'contact_no' => '09178887777',
        ])->assertCreated();
        $resident = ['Authorization' => 'Bearer ' . $reg->json('token')];

        // By default each role keeps the access it always had, and /me lists it for the menu.
        $this->assertContains('requests.review', $this->getJson('/api/me', $this->token($staff))->json('permissions'));
        $this->assertContains('restock.create', $reg->json('user.permissions'));

        // Only the administrator can see and change permissions.
        $this->getJson('/api/permissions', $this->token($staff))->assertForbidden();
        $this->putJson('/api/permissions/staff', ['permissions' => ['requests.review' => false]], $this->token($staff))->assertForbidden();
        $this->getJson('/api/permissions', $resident)->assertForbidden();
        $this->getJson('/api/permissions', $this->token($admin))->assertOk()
            ->assertJsonPath('roles.0.role', 'staff')->assertJsonPath('roles.1.role', 'resident');

        $requestId = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 5]]], $resident)
            ->assertCreated()->json('request_id');

        // The administrator stops staff from approving requests and recording stock.
        $this->putJson('/api/permissions/staff', ['permissions' => ['requests.review' => false, 'inventory.manage' => false]], $this->token($admin))->assertOk();
        $this->postJson("/api/requests/{$requestId}/approve", [], $this->token($staff))
            ->assertForbidden()->assertJsonPath('permission', 'requests.review');
        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $medicine->medicine_id, 'quantity' => 5, 'expiration_date' => now()->addYear()->toDateString()], $this->token($staff))
            ->assertForbidden();
        $this->getJson('/api/inventory', $this->token($staff))->assertOk(); // viewing stock is still allowed
        $this->assertNotContains('requests.review', $this->getJson('/api/me', $this->token($staff))->json('permissions'));

        // The administrator is never restricted.
        $this->postJson("/api/requests/{$requestId}/approve", [], $this->token($admin))->assertOk();

        // Residents: restock requests and profile editing turned off; medicine requests still allowed.
        $this->putJson('/api/permissions/resident', ['permissions' => ['restock.create' => false, 'profile.edit' => false]], $this->token($admin))->assertOk();
        $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => 50]]], $resident)
            ->assertForbidden();
        $this->putJson('/api/profile', ['name' => 'Lito Garcia'], $resident)->assertForbidden();
        $this->getJson('/api/profile', $resident)->assertOk(); // the QR code stays available

        // Unknown permissions are refused, and Reset brings back the default access.
        $this->putJson('/api/permissions/staff', ['permissions' => ['users.manage' => true]], $this->token($admin))->assertStatus(422);
        $this->postJson('/api/permissions/staff/reset', [], $this->token($admin))->assertOk();
        $this->assertContains('requests.review', $this->getJson('/api/me', $this->token($staff))->json('permissions'));
    }

    public function test_new_staff_must_change_temporary_password_at_first_log_in(): void
    {
        $admin = User::factory()->admin()->create();
        $created = $this->postJson('/api/users', ['name' => 'Carlo Reyes', 'email' => 'carlo.reyes@gmail.com', 'role' => 'staff'], $this->token($admin))
            ->assertCreated()->assertJsonPath('user.must_change_password', true);
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));

        // The system makes the temporary password (12 random letters and numbers) and stores only its hash.
        $temporary = $created->json('temporary_password');
        $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{12}$/', $temporary);
        $staff = User::find($created->json('user.user_id'));
        $this->assertNotSame($temporary, $staff->password_hash);
        $this->assertTrue(Hash::check($temporary, $staff->password_hash));

        // It is never shown again: not in the user list, and no password (or hash) is ever returned.
        $list = $this->getJson('/api/users', $this->token($admin))->assertOk()->getContent();
        $this->assertStringNotContainsString($temporary, $list);
        $this->assertStringNotContainsString('password_hash', $list);

        // The administrator cannot choose or set a password when editing.
        $this->putJson("/api/users/{$staff->user_id}", ['name' => $staff->name, 'email' => $staff->email, 'role' => 'staff',
            'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $this->token($admin))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // First log-in with the temporary password: only "change password" is possible.
        $login = $this->postJson('/api/login', ['login' => 'carlo.reyes@gmail.com', 'password' => $temporary])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $headers = ['Authorization' => 'Bearer ' . $login->json('token')];
        $this->getJson('/api/me', $headers)->assertOk();
        $this->getJson('/api/medicines', $headers)->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // The temporary password must be correct, and the new one must be different and not easy to guess.
        $this->postJson('/api/account/password', ['current_password' => 'wrong123', 'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/account/password', ['current_password' => $temporary, 'password' => $temporary, 'password_confirmation' => $temporary], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/account/password', ['current_password' => $temporary, 'password' => 'Carlo2026x', 'password_confirmation' => 'Carlo2026x'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('password'); // contains the name
        $this->postJson('/api/account/password', ['current_password' => $temporary, 'password' => 'MyOwnPass9', 'password_confirmation' => 'MyOwnPass9'], $headers)
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/medicines', $headers)->assertOk();

        // Lost password: the administrator can only replace it with a new temporary password (shown once).
        // The password the staff member chose is never revealed, and stops working.
        $reset = $this->postJson("/api/users/{$staff->user_id}/reset-password", [], $this->token($admin))
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $newTemporary = $reset->json('temporary_password');
        $this->assertNotSame($temporary, $newTemporary);
        $this->assertStringContainsString('no-store', (string) $reset->headers->get('Cache-Control'));
        $this->getJson('/api/me', $headers)->assertUnauthorized(); // logged out everywhere
        $this->postJson('/api/login', ['login' => 'carlo.reyes@gmail.com', 'password' => 'MyOwnPass9'])->assertStatus(422);
        $this->postJson('/api/login', ['login' => 'carlo.reyes@gmail.com', 'password' => $newTemporary])
            ->assertOk()->assertJsonPath('user.must_change_password', true);

        // Only the administrator can reset, and never their own password (that needs the current password).
        $this->postJson("/api/users/{$staff->user_id}/reset-password", [], $this->token(User::factory()->create()))->assertForbidden();
        $this->postJson("/api/users/{$admin->user_id}/reset-password", [], $this->token($admin))->assertStatus(422);

        // An unused temporary password stops working after a few days.
        $this->travel(config('botika.temp_password_days') + 1)->days();
        $this->postJson('/api/login', ['login' => 'carlo.reyes@gmail.com', 'password' => $newTemporary])
            ->assertStatus(422)->assertJsonValidationErrors('login');
    }

    public function test_password_policy_and_changing_own_password(): void
    {
        // The same password policy applies everywhere a password is chosen.
        $resident = ['name' => 'Maria Santos', 'barangay' => 'Zone I Poblacion', 'contact_no' => '09175550001'];
        foreach (['password123', 'Maria2026x', 'Botika2026', 'x09175550001'] as $weak) {
            $this->postJson('/api/register', $resident + ['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $admin = User::factory()->admin()->create();

        // Staff change their own password with the current one; other devices are logged out.
        $staff = User::factory()->create(['email' => 'joy.staff@gmail.com']);
        $other = $this->token($staff);
        $here = $this->token($staff);
        $this->postJson('/api/account/password', ['current_password' => 'password', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $here)
            ->assertOk();
        $this->getJson('/api/me', $here)->assertOk();
        $this->getJson('/api/me', $other)->assertUnauthorized();
        $this->postJson('/api/login', ['login' => 'joy.staff@gmail.com', 'password' => 'Kalinga4821'])->assertOk();

        // Only wrong passwords are counted: after 5 wrong tries even the right one must wait.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/account/password', ['current_password' => "wrong{$i}x", 'password' => 'Masagana7302', 'password_confirmation' => 'Masagana7302'], $here)
                ->assertStatus(422)->assertJsonValidationErrors('current_password');
        }
        $this->postJson('/api/account/password', ['current_password' => 'Kalinga4821', 'password' => 'Masagana7302', 'password_confirmation' => 'Masagana7302'], $here)
            ->assertStatus(422)->assertJsonPath('errors.current_password.0', fn ($m) => str_starts_with($m, 'Too many wrong passwords'));

        // A temporary password that has expired cannot be used to set a new password either.
        $new = $this->postJson('/api/users', ['name' => 'Lea Santos', 'email' => 'lea.santos@gmail.com', 'role' => 'staff'], $this->token($admin))->assertCreated();
        $temporary = $new->json('temporary_password');
        $this->travel(config('botika.temp_password_days') * 24 - 1)->hours();
        $login = $this->postJson('/api/login', ['login' => 'lea.santos@gmail.com', 'password' => $temporary])->assertOk();
        $this->travel(2)->hours();
        $this->postJson('/api/account/password', ['current_password' => $temporary, 'password' => 'Sariling8841', 'password_confirmation' => 'Sariling8841'],
            ['Authorization' => 'Bearer ' . $login->json('token')])
            ->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Your temporary password has expired. Please ask the administrator to reset it.');
        $this->assertTrue(User::find($new->json('user.user_id'))->must_change_password);
    }

    public function test_staff_and_admin_my_profile(): void
    {
        $staff = User::factory()->create(['name' => 'Joy Mercado', 'email' => 'joy.mercado@gmail.com']);
        $here = $this->token($staff);
        $other = $this->token($staff);

        // Name only; the email (username) is changed by the administrator.
        $this->putJson('/api/account', ['name' => 'Joy  Mercado-Cruz'], $here)->assertOk()
            ->assertJsonPath('user.name', 'Joy Mercado-Cruz')->assertJsonPath('password_changed', false);
        $this->putJson('/api/account', ['name' => 'Joy Mercado', 'email' => 'new@gmail.com'], $here)
            ->assertStatus(422)->assertJsonValidationErrors('email');

        // Changing the password here follows the same rules: wrong current password saves nothing.
        $this->putJson('/api/account', ['name' => 'Joy M. Cruz', 'current_password' => 'wrong123', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $here)
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertSame('Joy Mercado-Cruz', $staff->fresh()->name);
        $this->putJson('/api/account', ['name' => 'Joy M. Cruz', 'current_password' => 'password', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821'], $here)
            ->assertOk()->assertJsonPath('password_changed', true);
        $this->getJson('/api/me', $here)->assertOk();
        $this->getJson('/api/me', $other)->assertUnauthorized();
        $this->postJson('/api/login', ['login' => 'joy.mercado@gmail.com', 'password' => 'Kalinga4821'])->assertOk();

        // Residents use their own Edit Profile.
        $resident = $this->postJson('/api/register', ['name' => 'Ben Ramos', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'barangay' => 'Zone I Poblacion', 'contact_no' => '09176664444'])->assertCreated();
        $this->putJson('/api/account', ['name' => 'Ben Ramos'], ['Authorization' => 'Bearer ' . $resident->json('token')])->assertForbidden();
    }
}
