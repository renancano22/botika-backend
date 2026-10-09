<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Resident;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Notification System (DFD Process 4.0): SMS to residents through the Semaphore SMS API. */
class SmsService
{
    /**
     * Sends the SMS and keeps it in the resident's in-app notifications.
     * $type: approved | rejected | dispensed | cancelled | expired | reminder | available |
     *        announcement | closure | hours | distribution
     */
    public function notify(Resident $resident, string $message, ?int $requestId = null, string $type = 'announcement'): Notification
    {
        $status = $this->send($resident->contact_no, $message);

        return Notification::create([
            'resident_id' => $resident->resident_id,
            'request_id' => $requestId,
            'type' => $type,
            'message' => $message,
            'channel' => 'sms',
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    /**
     * In-app notification only (no SMS, so no SMS credits are used), e.g. "request submitted".
     */
    public function notifyInApp(Resident $resident, string $message, ?int $requestId, string $type): Notification
    {
        return Notification::create([
            'resident_id' => $resident->resident_id,
            'request_id' => $requestId,
            'type' => $type,
            'message' => $message,
            'channel' => 'app',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Sends an SMS without saving it to the notifications log (used for password reset codes).
     * @return string sent | failed | logged
     */
    public function sendTo(string $number, string $message): string
    {
        return $this->send($number, $message);
    }

    /** @return string sent | failed | logged */
    protected function send(string $number, string $message): string
    {
        $apiKey = config('sms.semaphore.api_key');

        if (empty($apiKey)) {
            Log::info("[SMS not sent - no SEMAPHORE_API_KEY] to {$number}: {$message}");
            return 'logged';
        }

        try {
            $payload = ['apikey' => $apiKey, 'number' => $number, 'message' => $message];
            if ($sender = config('sms.semaphore.sender_name')) {
                $payload['sendername'] = $sender;
            }

            $response = Http::asForm()->timeout(10)->post(config('sms.semaphore.url'), $payload);

            if ($response->successful()) {
                return 'sent';
            }
            Log::warning('Semaphore SMS failed: ' . $response->body());
        } catch (Throwable $e) {
            Log::warning('Semaphore SMS error: ' . $e->getMessage());
        }

        return 'failed';
    }
}
