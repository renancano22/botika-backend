<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginAttempts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Repeated log-in attempts within a short time are temporarily blocked to protect accounts from guessing. */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_wrong_passwords_block_log_in_for_a_while(): void
    {
        User::factory()->create(['email' => 'staff@example.com']); // password: "password"

        // Wrong passwords: the last tries say how many are left.
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess1'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Invalid email/mobile number or password.');
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess2'])->assertStatus(422);
        $this->postJson('/api/login', ['login' => 'STAFF@example.com', 'password' => 'guess3'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Invalid email/mobile number or password. 2 more attempts before logging in is temporarily blocked.');
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess4'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Invalid email/mobile number or password. 1 more attempt before logging in is temporarily blocked.');
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess5'])->assertStatus(429)
            ->assertHeader('Retry-After', (string) LoginAttempts::BLOCK_SECONDS)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'temporarily blocked') && str_contains($m, '15 minutes'));

        // While blocked, even the correct password is refused (guessing cannot continue).
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'password'])->assertStatus(429);

        // After the block ends, the correct password works and the count starts over.
        $this->travel(LoginAttempts::BLOCK_SECONDS + 1)->seconds();
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess6'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Invalid email/mobile number or password.');
    }

    public function test_a_correct_log_in_resets_the_count_and_unknown_accounts_look_the_same(): void
    {
        User::factory()->create(['email' => 'staff@example.com']);
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => "guess{$i}"])->assertStatus(422);
        }
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => 'staff@example.com', 'password' => 'guess5'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Invalid email/mobile number or password.');

        // An email that is not registered is blocked the same way (no hint that the account does not exist).
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/login', ['login' => 'nobody@example.com', 'password' => "guess{$i}"])->assertStatus(422);
        }
        $this->postJson('/api/login', ['login' => 'nobody@example.com', 'password' => 'guess5'])->assertStatus(429);
    }
}
