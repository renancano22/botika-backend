<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sms.semaphore.api_key' => 'fake-key', 'sms.semaphore.sender_name' => 'Botika']);
        Http::preventStrayRequests();
    }

    public function test_mobile_formats_are_normalized_and_only_accepted_messages_succeed(): void
    {
        Http::fake(['*' => Http::response([['message_id' => 123, 'status' => 'Queued']])]);
        foreach (['09171234567', '9171234567', '639171234567', '+63 917-123-4567'] as $number) {
            $this->assertSame('sent', app(SmsService::class)->sendTo($number, 'BulanBotikaCare: request approved.'));
        }
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('639171234567', $request['number']);
            $this->assertSame('Botika', $request['sendername']);
            $this->assertSame('fake-key', $request['apikey']);
        }
    }

    public function test_invalid_or_multiple_recipients_do_not_use_credits(): void
    {
        Http::fake();
        foreach (['', '123', '09171234567,09181234567', '+12025550123'] as $number) {
            $this->assertSame('failed', app(SmsService::class)->sendTo($number, 'Hello'));
        }
        Http::assertNothingSent();
    }

    public function test_http_success_does_not_hide_provider_failures(): void
    {
        foreach ([['error' => 'Insufficient credits'], [], [['message_id' => 2, 'status' => 'Failed']],
            [['message_id' => 2, 'status' => 'Refunded']], [['message_id' => 2]], [['status' => 'Sent']]] as $body) {
            Http::fake(['*' => Http::response($body)]);
            $this->assertSame('failed', app(SmsService::class)->sendTo('09171234567', 'Hello'));
        }
    }

    public function test_missing_key_logs_without_calling_semaphore(): void
    {
        config(['sms.semaphore.api_key' => '   ']);
        Http::fake();
        $this->assertFalse(app(SmsService::class)->ready());
        $this->assertSame('logged', app(SmsService::class)->sendTo('09171234567', 'Hello'));
        Http::assertNothingSent();
    }

    public function test_timeout_is_not_retried_and_does_not_log_sensitive_exception_text(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException('apikey=fake-key&message=PrivatePassword123'));
        $this->assertSame('failed', app(SmsService::class)->sendTo('09171234567', 'PrivatePassword123', true));
        Log::shouldHaveReceived('warning')->once()->with('Semaphore SMS connection failed.', ['exception' => ConnectionException::class]);
    }

    public function test_provider_error_body_is_not_written_to_logs(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => 'fake-key PrivatePassword123'], 400)]);
        $this->assertSame('failed', app(SmsService::class)->sendTo('09171234567', 'PrivatePassword123', true));
        Log::shouldHaveReceived('warning')->once()->with('Semaphore SMS rejected.', ['http_status' => 400]);
    }

    private function fakeAccount(float $balance = 10, string $senderStatus = 'Active'): void
    {
        Http::fake([
            '*/account?*' => Http::response(['credit_balance' => $balance, 'status' => 'Active']),
            '*/account/sendernames*' => Http::response([['name' => 'Botika', 'status' => $senderStatus]]),
        ]);
    }

    public function test_sms_check_requires_key_and_sends_nothing(): void
    {
        config(['sms.semaphore.api_key' => null]);
        Http::fake();
        $this->artisan('botika:sms-check')->expectsOutputToContain('SEMAPHORE_API_KEY is missing')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_sms_check_verifies_credits_and_sender_without_sending_sms(): void
    {
        $this->fakeAccount();
        $this->artisan('botika:sms-check')->expectsOutputToContain('No SMS was sent.')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_sms_check_rejects_zero_credits(): void
    {
        $this->fakeAccount(0);
        $this->artisan('botika:sms-check')->expectsOutputToContain('No SMS credits available')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_sms_check_rejects_pending_or_mismatched_senders(): void
    {
        $this->fakeAccount(10, 'Pending');
        $this->artisan('botika:sms-check')->expectsOutputToContain('No matching active sender')->assertFailed();
        $this->fakeAccount();
        config(['sms.semaphore.sender_name' => 'WrongName']);
        $this->artisan('botika:sms-check')->expectsOutputToContain('No matching active sender')->assertFailed();
    }
}
