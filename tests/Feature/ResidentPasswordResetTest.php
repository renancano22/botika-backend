<?php

namespace Tests\Feature;

use App\Mail\TemporaryPasswordMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Admin → User Accounts → Reset Password for a resident account: the new temporary password is sent only to
 * the resident (SMS and email). The administrator never sees it; it is never logged or kept in the SMS log.
 */
class ResidentPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function admin(): array
    {
        return ['Authorization' => 'Bearer ' . User::factory()->admin()->create()->createToken('t')->plainTextToken];
    }

    private function resident(?string $email = 'ana.cruz@gmail.com'): array
    {
        $reg = $this->postJson('/api/register', array_filter(['name' => 'Ana Cruz', 'email' => $email, 'password' => 'Kalinga4821',
            'password_confirmation' => 'Kalinga4821', 'barangay' => 'Gate', 'contact_no' => '09171234567']))->assertCreated();
        return [User::find($reg->json('user.user_id')), ['Authorization' => 'Bearer ' . $reg->json('token')]];
    }

    private function smsReady(int $status = 200): void
    {
        config(['sms.semaphore.api_key' => 'test-key']);
        Http::fake(['*' => Http::response([['message_id' => 1]], $status)]);
    }

    public function test_reset_password_is_sent_to_the_resident_by_sms_and_email_and_not_shown_to_the_admin(): void
    {
        $this->smsReady();
        Mail::fake();
        [$resident, $headers] = $this->resident();

        $res = $this->postJson("/api/users/{$resident->user_id}/reset-password", [], $this->admin())->assertOk()
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonPath('sent_to.sms', '09171234567')->assertJsonPath('sent_to.email', 'ana.cruz@gmail.com')
            ->assertJsonPath('user.must_change_password', true);

        // Email: to the resident only.
        $password = null;
        Mail::assertSent(TemporaryPasswordMail::class, function (TemporaryPasswordMail $mail) use (&$password) {
            $password = $mail->password;
            return $mail->hasTo('ana.cruz@gmail.com') && ! $mail->hasTo('admin@gmail.com');
        });
        $this->assertNotEmpty($password);
        $this->assertStringContainsString($password, Mail::sent(TemporaryPasswordMail::class)->first()->render());

        // SMS: to the resident's registered mobile number, with the same temporary password.
        Http::assertSent(fn (HttpRequest $r) => $r['number'] === '09171234567' && str_contains($r['message'], $password));

        // The administrator's response, the SMS log and the in-app notifications never contain it.
        $this->assertStringNotContainsString($password, $res->getContent());
        $this->assertSame(0, Notification::where('message', 'like', "%{$password}%")->count());

        // The resident is logged out, the old password stops working and the new one must be changed at log-in.
        $this->getJson('/api/me', $headers)->assertUnauthorized();
        $this->postJson('/api/login', ['login' => '09171234567', 'password' => 'Kalinga4821'])->assertStatus(422);
        $this->postJson('/api/login', ['login' => '09171234567', 'password' => $password])->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_resident_without_email_gets_it_by_sms(): void
    {
        $this->smsReady();
        Mail::fake();
        [$resident] = $this->resident(null);

        $this->postJson("/api/users/{$resident->user_id}/reset-password", [], $this->admin())->assertOk()
            ->assertJsonPath('sent_to.sms', '09171234567')->assertJsonPath('sent_to.email', null)
            ->assertJsonMissingPath('temporary_password');
        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_nothing_changes_when_the_password_cannot_be_sent(): void
    {
        // SMS and email not set up on the server.
        config(['sms.semaphore.api_key' => null, 'mail.default' => 'log']);
        [$resident] = $this->resident();
        $this->postJson("/api/users/{$resident->user_id}/reset-password", [], $this->admin())->assertStatus(422)
            ->assertJsonMissingPath('temporary_password');
        $this->assertFalse((bool) $resident->fresh()->must_change_password);
        $this->postJson('/api/login', ['login' => '09171234567', 'password' => 'Kalinga4821'])->assertOk();

        // The SMS could not be sent and there is no email: the current password is kept.
        $this->smsReady(500);
        $resident->update(['email' => null]);
        $this->postJson("/api/users/{$resident->user_id}/reset-password", [], $this->admin())->assertStatus(422);
        $this->assertFalse((bool) $resident->fresh()->must_change_password);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('password_histories')->where('user_id', $resident->user_id)->count());
        $this->postJson('/api/login', ['login' => '09171234567', 'password' => 'Kalinga4821'])->assertOk();
    }

    public function test_sms_with_a_password_is_never_written_to_the_log(): void
    {
        config(['sms.semaphore.api_key' => null]);
        Log::spy();
        app(SmsService::class)->sendTo('09171234567', 'Temporary password: Secret8Pass2', secret: true);
        Log::shouldHaveReceived('info')->with(Mockery::on(fn ($m) => ! str_contains($m, 'Secret8Pass2')))->once();
    }

    public function test_staff_reset_still_shows_the_temporary_password_once(): void
    {
        $staff = User::factory()->create();
        $this->postJson("/api/users/{$staff->user_id}/reset-password", [], $this->admin())->assertOk()
            ->assertJsonStructure(['temporary_password'])->assertJsonMissingPath('sent_to');
    }
}
