<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckSms extends Command
{
    protected $signature = 'botika:sms-check';
    protected $description = 'Check Semaphore credentials, credits and active sender names without sending an SMS';

    public function handle(): int
    {
        $key = trim((string) config('sms.semaphore.api_key'));
        if ($key === '') {
            $this->error('SEMAPHORE_API_KEY is missing. Set it in the backend environment, then rebuild cached configuration.');
            return self::FAILURE;
        }

        try {
            $account = Http::timeout(10)->get('https://api.semaphore.co/api/v4/account', ['apikey' => $key]);
            if (! $account->successful() || ! is_numeric($account->json('credit_balance'))) {
                $this->error('Semaphore account check failed. Check the API key or try again after any rate limit has cleared.');
                return self::FAILURE;
            }
            $balance = (float) $account->json('credit_balance');
            $this->line('SMS credits: ' . $balance);
            if ($balance <= 0) {
                $this->error('No SMS credits available. Add credits in Semaphore before sending.');
                return self::FAILURE;
            }
            if (strtolower((string) $account->json('status')) !== 'active') {
                $this->error('The Semaphore account is not active. Check its status in Semaphore.');
                return self::FAILURE;
            }

            $senders = Http::timeout(10)->get('https://api.semaphore.co/api/v4/account/sendernames', ['apikey' => $key, 'limit' => 1000]);
            $rows = $senders->json();
            if (! $senders->successful() || ! is_array($rows) || ! array_is_list($rows)) {
                $this->error('Could not check sender names. Check them in Semaphore or retry later.');
                return self::FAILURE;
            }
            $active = array_column(array_filter($rows, fn ($row) => is_array($row)
                && strtolower((string) ($row['status'] ?? '')) === 'active'), 'name');
            $sender = trim((string) config('sms.semaphore.sender_name'));
            if ($active === [] || ($sender !== '' && ! in_array($sender, $active, true))) {
                $this->error('No matching active sender name. Activate a sender in Semaphore and set SEMAPHORE_SENDER_NAME to its exact name.');
                return self::FAILURE;
            }
            $this->info('Semaphore is configured with credits and an active sender. No SMS was sent.');
            $this->line('A real recipient test is still needed to verify delivery.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Cannot connect to Semaphore. Check backend HTTPS access and retry.');
            return self::FAILURE;
        }
    }
}
