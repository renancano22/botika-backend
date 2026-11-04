<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The administrator and pharmacy staff get notifications in the app (the bell): new medicine requests to review,
 * new restock requests (administrator), and requests cancelled by the resident. Expiry alerts stay separate.
 */
class StaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . $u->createToken('t')->plainTextToken];
    }

    public function test_staff_and_admin_are_notified_of_new_and_cancelled_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $syrup = Medicine::create(['medicine_name' => 'Lagundi Syrup', 'category' => 'Anticough', 'unit' => 'bottle', 'reorder_level' => 5, 'stock_verified_at' => now()]);
        $ana = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->assertCreated();
        $res = ['Authorization' => 'Bearer ' . $ana->json('token')];

        // A medicine request: the staff and the administrator are notified.
        $id = $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $para->medicine_id, 'quantity' => 5]]], $res)
            ->assertCreated()->json('request_id');
        $this->getJson('/api/staff-notifications/unread-count', $this->h($staff))->assertJsonPath('unread', 1);
        $this->getJson('/api/staff-notifications', $this->h($staff))->assertOk()
            ->assertJsonPath('data.0.type', 'new_request')->assertJsonPath('data.0.title', 'New medicine request')
            ->assertJsonPath('data.0.request_id', $id)
            ->assertJsonPath('data.0.message', fn ($m) => str_contains($m, "Request No. {$id} from Ana Cruz") && str_contains($m, 'Paracetamol'));
        $this->getJson('/api/staff-notifications/unread-count', $this->h($admin))->assertJsonPath('unread', 1);

        // A restock request: only the administrator (who reviews restock requests).
        $this->postJson('/api/requests', ['request_type' => 'restock', 'items' => [['medicine_id' => $syrup->medicine_id]]], $res)->assertCreated();
        $this->getJson('/api/staff-notifications/unread-count', $this->h($staff))->assertJsonPath('unread', 1);
        $this->getJson('/api/staff-notifications', $this->h($admin))->assertJsonPath('unread', 2)->assertJsonPath('data.0.type', 'new_restock');

        // Cancelled by the resident.
        $this->postJson("/api/requests/{$id}/cancel", [], $res)->assertOk();
        $this->getJson('/api/staff-notifications', $this->h($staff))->assertJsonPath('data.0.type', 'request_cancelled');

        // Read: one, then all; each user only sees and marks their own.
        $first = StaffNotification::where('user_id', $staff->user_id)->orderBy('staff_notification_id')->first();
        $this->postJson("/api/staff-notifications/{$first->staff_notification_id}/read", [], $this->h($admin))->assertNotFound();
        $this->postJson("/api/staff-notifications/{$first->staff_notification_id}/read", [], $this->h($staff))->assertOk();
        $this->getJson('/api/staff-notifications/unread-count', $this->h($staff))->assertJsonPath('unread', 1);
        $this->postJson('/api/staff-notifications/read-all', [], $this->h($staff))->assertOk();
        $this->getJson('/api/staff-notifications/unread-count', $this->h($staff))->assertJsonPath('unread', 0);
        $this->getJson('/api/staff-notifications/unread-count', $this->h($admin))->assertJsonPath('unread', 3);

        // Residents have their own notifications, not these.
        $this->getJson('/api/staff-notifications', $res)->assertForbidden();
    }

    public function test_staff_without_review_permission_are_not_notified(): void
    {
        $staff = User::factory()->create();
        $this->putJson('/api/permissions/staff', ['permissions' => ['requests.review' => false]],
            $this->h(User::factory()->admin()->create()))->assertOk();
        $para = Medicine::create(['medicine_name' => 'Paracetamol 500mg', 'category' => 'Analgesic', 'unit' => 'tablet', 'reorder_level' => 5]);
        Inventory::create(['medicine_id' => $para->medicine_id, 'quantity' => 50, 'expiration_date' => now()->addYear()->toDateString()]);
        $token = $this->postJson('/api/register', ['name' => 'Ana Cruz', 'password' => 'Kalinga4821', 'password_confirmation' => 'Kalinga4821',
            'barangay' => 'Gate', 'contact_no' => '09171234567'])->json('token');
        $this->postJson('/api/requests', ['request_type' => 'medicine', 'items' => [['medicine_id' => $para->medicine_id, 'quantity' => 5]]],
            ['Authorization' => "Bearer {$token}"])->assertCreated();
        $this->assertSame(0, StaffNotification::where('user_id', $staff->user_id)->count());
    }
}
