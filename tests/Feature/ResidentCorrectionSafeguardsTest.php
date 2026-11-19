<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Resident;
use App\Models\ResidentCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ResidentCorrectionSafeguardsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $user;
    private Resident $resident;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Santos']);
        $this->user = User::factory()->create(['role' => 'resident', 'name' => 'Ana Cruz', 'email' => 'ana.cruz@gmail.com']);
        $this->resident = Resident::create(['user_id' => $this->user->user_id, 'name' => 'Ana Cruz', 'address' => 'Purok 2, Gate, Bulan, Sorsogon', 'contact_no' => '09171234567', 'qr_code' => 'BBC-000001']);
    }

    private function headers(?User $user = null): array { return ['Authorization' => 'Bearer ' . ($user ?? $this->admin)->createToken('test')->plainTextToken]; }
    private function payload(array $replace = []): array
    {
        return array_replace(['name' => 'Ana Maria Cruz', 'email' => 'ana.maria@gmail.com', 'role' => 'resident', 'barangay' => 'Bical',
            'identity_verified' => true, 'verification_method' => 'valid_id', 'correction_reason' => 'Corrected registration details after checking the resident ID.'], $replace);
    }
    private function url(): string { return "/api/users/{$this->user->user_id}"; }
    private function history(): string { return "/api/residents/{$this->resident->resident_id}/corrections"; }
    private function assertNoCorrection(): void
    {
        $this->assertSame('Ana Cruz', $this->resident->fresh()->name);
        $this->assertSame('ana.cruz@gmail.com', $this->user->fresh()->email);
        $this->assertDatabaseCount('resident_corrections', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_changed_details_require_verification_method_and_meaningful_reason(): void
    {
        foreach ([['identity_verified' => false], ['verification_method' => ''], ['verification_method' => 'unchecked'], ['correction_reason' => ['invalid']], ['correction_reason' => '  '], ['correction_reason' => 'short'], ['correction_reason' => str_repeat('a', 501)]] as $bad) {
            $this->putJson($this->url(), $this->payload($bad), $this->headers())->assertUnprocessable();
            $this->assertNoCorrection();
        }
        $this->putJson($this->url(), ['name' => 'Ana Maria Cruz', 'email' => 'ana.cruz@gmail.com', 'role' => 'resident', 'barangay' => 'Gate'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['identity_verified', 'verification_method', 'correction_reason']);
        $this->assertNoCorrection();
    }

    public function test_success_saves_exact_old_and_new_details_actor_reason_and_resident_notice(): void
    {
        $hash = $this->user->password_hash;
        $this->putJson($this->url(), $this->payload(['actor_user_id' => 9999, 'actor_name' => 'Forged actor']), $this->headers())->assertOk()
            ->assertJsonPath('resident.qr_code', 'BBC-000001')->assertJsonPath('resident.address', 'Purok 2, Bical, Bulan, Sorsogon');
        $audit = ResidentCorrection::firstOrFail();
        $this->assertSame($this->admin->user_id, $audit->actor_user_id);
        $this->assertSame('Admin Santos', $audit->actor_name);
        $this->assertSame(['from' => 'Ana Cruz', 'to' => 'Ana Maria Cruz'], $audit->changes['name']);
        $this->assertSame(['from' => 'ana.cruz@gmail.com', 'to' => 'ana.maria@gmail.com'], $audit->changes['email']);
        $this->assertSame(['from' => 'Gate', 'to' => 'Bical'], $audit->changes['barangay']);
        $this->assertSame('valid_id', $audit->verification_method);
        $this->assertNotNull($audit->verified_at);
        $this->assertSame($hash, $this->user->fresh()->password_hash);
        $this->assertDatabaseHas('notifications', ['resident_id' => $this->resident->resident_id, 'type' => 'account_updated', 'channel' => 'app']);
        $notice = Notification::firstOrFail();
        $this->assertStringContainsString('contact Botika ng Bayan', $notice->message);
        $this->assertStringNotContainsString('ana.maria@gmail.com', $notice->message);
        $this->getJson($this->history(), $this->headers())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.actor_name', 'Admin Santos')->assertJsonPath('data.0.reason', $this->payload()['correction_reason']);
        $this->getJson('/api/notifications', $this->headers($this->user))->assertOk()->assertJsonPath('0.type', 'account_updated');
    }

    public function test_counter_correction_route_cannot_bypass_safeguards_even_for_linked_accounts(): void
    {
        $url = "/api/residents/{$this->resident->resident_id}";
        $this->putJson($url, ['name' => 'Ana Maria Cruz', 'barangay' => 'Gate'], $this->headers())->assertUnprocessable();
        $this->assertNoCorrection();
        $this->putJson($url, $this->payload(), $this->headers())->assertOk();
        $this->assertSame('Ana Maria Cruz', $this->user->fresh()->name);
        $this->assertSame('ana.cruz@gmail.com', $this->user->fresh()->email); // This endpoint cannot edit email.
        $this->assertDatabaseCount('resident_corrections', 1);
        $this->assertArrayNotHasKey('email', ResidentCorrection::first()->changes);
    }

    public function test_counter_record_without_account_is_audited_and_keeps_its_resident_id(): void
    {
        $counter = Resident::create(['name' => 'Lola Santos', 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => null, 'qr_code' => 'BBC-000002']);
        $this->putJson("/api/residents/{$counter->resident_id}", $this->payload(['name' => 'Lola Maria Santos', 'verification_method' => 'barangay_id']), $this->headers())
            ->assertOk()->assertJsonPath('qr_code', 'BBC-000002');
        $this->assertDatabaseHas('resident_corrections', ['resident_id' => $counter->resident_id, 'verification_method' => 'barangay_id']);
        $this->assertDatabaseHas('notifications', ['resident_id' => $counter->resident_id, 'channel' => 'app']);
    }

    public function test_email_only_edit_does_not_rewrite_legacy_address(): void
    {
        $this->resident->update(['address' => 'Old address typed manually']);
        $data = $this->payload(['name' => 'Ana Cruz']);
        unset($data['barangay']);
        $this->putJson($this->url(), $data, $this->headers())->assertOk();
        $this->assertSame('Old address typed manually', $this->resident->fresh()->address);
        $this->assertSame(['email'], array_keys(ResidentCorrection::first()->changes));
    }

    public function test_duplicate_identity_and_duplicate_email_are_rejected_without_partial_change(): void
    {
        Resident::create(['name' => 'Ana Maria Cruz', 'address' => 'Bical, Bulan, Sorsogon', 'contact_no' => null, 'qr_code' => 'BBC-000002']);
        $this->putJson($this->url(), $this->payload(), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertNoCorrection();
        User::factory()->create(['email' => 'used@gmail.com']);
        $this->putJson($this->url(), $this->payload(['email' => 'used@gmail.com']), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNoCorrection();
    }

    public function test_staff_residents_and_anonymous_users_cannot_correct_or_read_audit_history(): void
    {
        foreach ([User::factory()->create(['role' => 'staff']), $this->user] as $actor) {
            $headers = $this->headers($actor);
            $this->putJson($this->url(), $this->payload(), $headers)->assertForbidden();
            $this->putJson("/api/residents/{$this->resident->resident_id}", $this->payload(), $headers)->assertForbidden();
            $this->getJson($this->history(), $headers)->assertForbidden();
        }
        $this->getJson($this->history())->assertUnauthorized();
        $this->assertNoCorrection();
    }

    public function test_identical_retry_does_not_repeat_audit_or_notification(): void
    {
        $this->putJson($this->url(), $this->payload(), $this->headers())->assertOk();
        $this->putJson($this->url(), $this->payload(), $this->headers())->assertOk();
        $this->assertDatabaseCount('resident_corrections', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_failed_notification_rolls_back_details_and_audit(): void
    {
        Notification::creating(fn () => throw new RuntimeException('Simulated storage failure'));
        $this->withoutExceptionHandling();
        try {
            $this->putJson($this->url(), $this->payload(), $this->headers());
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated storage failure', $e->getMessage());
        } finally { Notification::flushEventListeners(); }
        $this->assertNoCorrection();
    }

    public function test_audit_records_cannot_be_updated_or_deleted_by_the_application(): void
    {
        $this->putJson($this->url(), $this->payload(), $this->headers())->assertOk();
        $audit = ResidentCorrection::firstOrFail();
        foreach (['update', 'delete'] as $action) {
            try {
                $action === 'update' ? $audit->update(['reason' => 'Changed afterwards']) : $audit->delete();
                $this->fail('History must be append-only.');
            } catch (LogicException) { $this->assertDatabaseCount('resident_corrections', 1); }
        }
        $this->assertSame($this->payload()['correction_reason'], $audit->fresh()->reason);
    }

    public function test_staff_account_edit_still_works_without_resident_verification(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->putJson("/api/users/{$staff->user_id}", ['name' => 'Carlo Santos', 'email' => $staff->email, 'role' => 'staff'], $this->headers())->assertOk();
        $this->assertDatabaseCount('resident_corrections', 0);
    }
}

