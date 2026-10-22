<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Notification;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User Permissions: every feature is closed on the server when it is turned off (not only hidden in the menu),
 * features that depend on another one cannot be left on alone, and data that belongs to the administrator
 * is never sent to pharmacy staff.
 */
class PermissionAuditTest extends TestCase
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

    private function resident(): array
    {
        $reg = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated();
        return [User::find($reg->json('user.user_id')), ['Authorization' => 'Bearer ' . $reg->json('token')], $reg->json('user.resident.resident_id')];
    }

    public function test_staff_cannot_read_the_sms_log(): void
    {
        [, , $residentId] = $this->resident();
        Notification::create(['resident_id' => $residentId, 'type' => 'announcement', 'message' => 'BulanBotikaCare: private message', 'channel' => 'sms', 'status' => 'sent', 'sent_at' => now()]);
        $staff = User::factory()->create();

        // The SMS log (every resident's messages and mobile numbers) is for the administrator only.
        $this->getJson('/api/notifications', $this->token($staff))->assertForbidden();
        $this->getJson('/api/notifications?per_page=5', $this->token($staff))->assertForbidden();
        $this->getJson('/api/notifications?per_page=5', $this->token(User::factory()->admin()->create()))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_features_that_need_another_feature_follow_it(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);

        // Turning off "View medicine list" also turns off "Add and edit medicines" (it is done on that page).
        $res = $this->putJson('/api/permissions/staff', ['permissions' => ['medicines.view' => false]], $this->token($admin))->assertOk();
        $perms = collect($res->json('roles'))->firstWhere('role', 'staff')['permissions'];
        $this->assertFalse(collect($perms)->firstWhere('key', 'medicines.manage')['allowed']);
        $this->assertSame(['medicines.view'], collect($perms)->firstWhere('key', 'medicines.manage')['requires']);
        $this->putJson("/api/medicines/{$med->medicine_id}", ['generic_name' => 'Paracetamol', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5], $this->token($staff))->assertForbidden();
        $this->assertNotContains('medicines.manage', $this->getJson('/api/me', $this->token($staff))->json('permissions'));

        // Turning on a feature also turns on the one it needs.
        $res = $this->putJson('/api/permissions/staff', ['permissions' => ['medicines.manage' => true]], $this->token($admin))->assertOk();
        $perms = collect(collect($res->json('roles'))->firstWhere('role', 'staff')['permissions']);
        $this->assertTrue($perms->firstWhere('key', 'medicines.view')['allowed']);
        $this->assertTrue($perms->firstWhere('key', 'medicines.manage')['allowed']);

        // The same for approving requests (needs "View requests") and stock-in / stock-out (needs "View stock").
        $this->putJson('/api/permissions/staff', ['permissions' => ['requests.view' => false, 'inventory.view' => false]], $this->token($admin))->assertOk();
        $granted = $this->getJson('/api/me', $this->token($staff))->json('permissions');
        $this->assertNotContains('requests.review', $granted);
        $this->assertNotContains('inventory.manage', $granted);
        $this->postJson('/api/inventory/stock-in', ['medicine_id' => $med->medicine_id, 'quantity' => 5, 'expiration_date' => now()->addYear()->toDateString()], $this->token($staff))->assertForbidden();

        // Residents: requests are made on the Check Medicines page, and cancelled from My Requests.
        [$resident, $headers] = $this->resident();
        $this->putJson('/api/permissions/resident', ['permissions' => ['medicines.view' => false, 'requests.view' => false]], $this->token($admin))->assertOk();
        $granted = $this->getJson('/api/me', $headers)->json('permissions');
        $this->assertEmpty(array_intersect(['requests.create', 'restock.create', 'requests.cancel'], $granted));
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $med->medicine_id, 'quantity' => 1]]], $headers)->assertForbidden();

        // Requests are followed in My Requests: with "View and track requests" off, a resident cannot send them
        // either (they could not see the status, pickup date or upload a prescription).
        $this->postJson('/api/permissions/resident/reset', [], $this->token($admin))->assertOk();
        $res = $this->putJson('/api/permissions/resident', ['permissions' => ['requests.view' => false]], $this->token($admin))->assertOk();
        $perms = collect(collect($res->json('roles'))->firstWhere('role', 'resident')['permissions']);
        $this->assertSame(['medicines.view', 'requests.view'], $perms->firstWhere('key', 'requests.create')['requires']);
        $this->assertFalse($perms->firstWhere('key', 'requests.create')['allowed']);
        $this->assertFalse($perms->firstWhere('key', 'restock.create')['allowed']);
        $this->assertTrue($perms->firstWhere('key', 'medicines.view')['allowed']);
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $med->medicine_id, 'quantity' => 1]]], $headers)->assertForbidden();
        // Turning "Submit medicine requests" on again turns on both features it needs.
        $res = $this->putJson('/api/permissions/resident', ['permissions' => ['medicines.view' => false]], $this->token($admin))->assertOk();
        $res = $this->putJson('/api/permissions/resident', ['permissions' => ['requests.create' => true]], $this->token($admin))->assertOk();
        $perms = collect(collect($res->json('roles'))->firstWhere('role', 'resident')['permissions']);
        $this->assertTrue($perms->firstWhere('key', 'medicines.view')['allowed']);
        $this->assertTrue($perms->firstWhere('key', 'requests.view')['allowed']);
        $this->assertTrue($perms->firstWhere('key', 'requests.create')['allowed']);
        $this->assertFalse($perms->firstWhere('key', 'restock.create')['allowed']); // only what was asked for

        // Reset to default gives everything back.
        $this->postJson('/api/permissions/resident/reset', [], $this->token($admin))->assertOk();
        $this->assertContains('requests.create', $this->getJson('/api/me', $headers)->json('permissions'));
    }

    public function test_resident_dashboard_follows_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        [, $headers, $residentId] = $this->resident();
        $med = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        $r = MedicineRequest::create(['resident_id' => $residentId, 'request_type' => 'medicine', 'request_date' => now(), 'status' => 'pending']);
        RequestItem::create(['request_id' => $r->request_id, 'medicine_id' => $med->medicine_id, 'quantity' => 2]);
        Notification::create(['resident_id' => $residentId, 'type' => 'announcement', 'message' => 'Hello', 'channel' => 'app', 'status' => 'sent', 'sent_at' => now()]);

        $this->getJson('/api/dashboard', $headers)->assertOk()->assertJsonPath('counts.pending', 1)
            ->assertJsonCount(1, 'recent_requests')->assertJsonCount(1, 'recent_notifications');

        // Requests and notifications turned off: the dashboard does not show them either.
        $this->putJson('/api/permissions/resident', ['permissions' => ['requests.view' => false, 'notifications.view' => false, 'medicines.view' => false]], $this->token($admin))->assertOk();
        $this->getJson('/api/dashboard', $headers)->assertOk()
            ->assertJsonPath('sections', ['requests' => false, 'notifications' => false, 'medicines' => false])
            ->assertJsonMissingPath('recent_requests')->assertJsonMissingPath('recent_notifications')
            ->assertJsonMissingPath('counts.pending')->assertJsonMissingPath('counts.available_medicines');
    }

    public function test_resident_password_change_follows_edit_profile_permission(): void
    {
        $admin = User::factory()->admin()->create();
        [, $headers] = $this->resident();
        $this->putJson('/api/permissions/resident', ['permissions' => ['profile.edit' => false]], $this->token($admin))->assertOk();

        // "Edit profile" (which includes the password) is off: no other way around it.
        $body = ['current_password' => 'Kalinga4821', 'password' => 'Masagana7302', 'password_confirmation' => 'Masagana7302'];
        $this->putJson('/api/profile/password', $body, $headers)->assertForbidden();
        $this->postJson('/api/account/password', $body, $headers)->assertForbidden();
    }
}
