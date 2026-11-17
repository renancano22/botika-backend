<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $oldHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->user = User::factory()->create(['name' => 'Marina Flores', 'email' => 'marina.flores@gmail.com', 'role' => 'resident', 'password_hash' => 'Original4821']);
        Resident::create(['user_id' => $this->user->user_id, 'name' => $this->user->name, 'address' => 'Gate, Bulan, Sorsogon', 'contact_no' => '09171234567', 'qr_code' => 'BBC-000001']);
        $this->user->createToken('existing-session');
        $this->oldHash = $this->user->password_hash;
        DB::table('password_reset_codes')->insert(['user_id' => $this->user->user_id, 'code_hash' => Hash::make('654321'), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['login' => $this->user->email, 'code' => '654321', 'password' => 'Mapalad4821', 'password_confirmation' => 'Mapalad4821'], $changes);
    }

    private function assertUnchanged(): void
    {
        $this->assertSame($this->oldHash, $this->user->fresh()->password_hash);
        $this->assertSame(1, $this->user->tokens()->count());
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    public function test_check_verifies_all_requirements_without_changing_any_password_code_or_session(): void
    {
        $response = $this->postJson('/api/reset-password/check', $this->payload())->assertOk()->assertJsonPath('valid', true)
            ->assertJsonStructure(['code_expires_at'])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['valid', 'code_expires_at'], array_keys($response->json()));
        $this->assertUnchanged();
        $this->assertDatabaseHas('password_reset_codes', ['user_id' => $this->user->user_id, 'attempts' => 0]);
    }

    public function test_personal_details_and_any_mobile_number_are_refused_by_both_check_and_save(): void
    {
        foreach (['Marina4821', 'Flores4821', 'marina.flores4821', 'Hi09171234567', 'Hi09170000000', 'Hi+639170000000', 'Hi9170000000', 'Hi0917-000-0000', 'Botika4821', 'Password123'] as $password) {
            foreach (['/api/reset-password/check', '/api/reset-password'] as $endpoint) {
                $this->postJson($endpoint, $this->payload(['password' => $password, 'password_confirmation' => $password]))
                    ->assertUnprocessable()->assertJsonValidationErrors('password');
                $this->assertUnchanged();
            }
        }
    }

    public function test_wrong_code_attempts_are_shared_between_check_and_reset_and_enforced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($i % 2 ? '/api/reset-password' : '/api/reset-password/check', $this->payload(['code' => '111111']))
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->assertDatabaseHas('password_reset_codes', ['user_id' => $this->user->user_id, 'attempts' => 5]);
        $this->postJson('/api/reset-password/check', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/reset-password', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertUnchanged();
    }

    public function test_account_specific_feedback_requires_a_valid_unexpired_code(): void
    {
        foreach (['/api/reset-password/check', '/api/reset-password'] as $endpoint) {
            $this->postJson($endpoint, $this->payload(['login' => 'unknown@gmail.com', 'password' => 'Marina4821', 'password_confirmation' => 'Marina4821']))
                ->assertUnprocessable()->assertJsonValidationErrors('code')->assertJsonMissingPath('errors.password');
            $this->postJson($endpoint, $this->payload(['login' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors('login');
        }
        $this->travel(11)->minutes();
        $this->postJson('/api/reset-password/check', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/reset-password', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertUnchanged();
    }

    public function test_save_rechecks_current_password_rules_consumes_code_and_revokes_sessions(): void
    {
        $this->postJson('/api/reset-password/check', $this->payload())->assertOk();
        $this->postJson('/api/reset-password', $this->payload(['password' => 'Hi09170000000', 'password_confirmation' => 'Hi09170000000']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertUnchanged();
        $this->postJson('/api/reset-password', $this->payload())->assertOk();
        $this->assertTrue(Hash::check('Mapalad4821', $this->user->fresh()->password_hash));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertDatabaseCount('password_reset_codes', 0);
        $this->postJson('/api/reset-password', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_replaced_codes_and_deactivated_accounts_cannot_reset_after_precheck(): void
    {
        $this->postJson('/api/reset-password/check', $this->payload())->assertOk();
        DB::table('password_reset_codes')->where('user_id', $this->user->user_id)->update(['code_hash' => Hash::make('123456')]);
        $this->postJson('/api/reset-password', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->user->update(['is_active' => false]);
        $this->postJson('/api/reset-password/check', $this->payload(['code' => '123456']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertUnchanged();
    }
}
