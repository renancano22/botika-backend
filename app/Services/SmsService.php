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
        // Residents registered at the Botika without a cellphone: nothing to send, the message is only logged.
        if (blank($resident->contact_no)) {
            Log::info("[SMS not sent - resident {$resident->qr_code} has no mobile number]: {$message}");
            return Notification::create([
                'resident_id' => $resident->resident_id,
                'request_id' => $requestId,
                'type' => $type,
                'message' => $message,
                'channel' => 'none',
                'status' => 'logged',
                'sent_at' => now(),
            ]);
        }

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
     * Sends an SMS without saving it to the notifications log (used for password reset codes and temporary
     * passwords). $secret: the message contains a password, so it is never written to the log.
     * @return string sent | failed | logged
     */
    public function sendTo(string $number, string $message, bool $secret = false): string
    {
        return $this->send($number, $message, $secret);
    }

    /** SMS can really be sent (a Semaphore API key is set). */
    public function ready(): bool
    {
        return trim((string) config('sms.semaphore.api_key')) !== '';
    }

    /** @return string sent | failed | logged */
    protected function send(string $number, string $message, bool $secret = false): string
    {
        $apiKey = trim((string) config('sms.semaphore.api_key'));

        if (empty($apiKey)) {
            Log::info("[SMS not sent - no SEMAPHORE_API_KEY] to {$number}: " . ($secret ? '(message hidden: it contains a password)' : $message));
            return 'logged';
        }

        // Send to one Philippine mobile only, never a comma-separated recipient list.
        $number = preg_replace('/[\s()\-]/', '', $number);
        if (preg_match('/^09\d{9}$/', $number)) $number = '63' . substr($number, 1);
        elseif (preg_match('/^9\d{9}$/', $number)) $number = '63' . $number;
        elseif (preg_match('/^\+639\d{9}$/', $number)) $number = substr($number, 1);
        if (! preg_match('/^639\d{9}$/', $number)) {
            Log::warning('Semaphore SMS not sent: invalid Philippine mobile number.');
            return 'failed';
        }

        try {
            $payload = ['apikey' => $apiKey, 'number' => $number, 'message' => $message];
            if ($sender = trim((string) config('sms.semaphore.sender_name'))) {
                $payload['sendername'] = $sender;
            }

            $response = Http::asForm()->timeout(10)->post(config('sms.semaphore.url'), $payload);

            $messages = $response->json();
            $accepted = is_array($messages) && array_is_list($messages) && count($messages) === 1
                && is_array($messages[0]) && ! empty($messages[0]['message_id'])
                && in_array(strtolower((string) ($messages[0]['status'] ?? '')), ['queued', 'pending', 'sent'], true);
            // "sent" means accepted by Semaphore; Queued/Pending does not prove handset delivery.
            if ($response->successful() && $accepted) {
                return 'sent';
            }
            // Provider bodies/exceptions can echo credentials, reset codes or medical message content.
            Log::warning('Semaphore SMS rejected.', ['http_status' => $response->status()]);
        } catch (Throwable $e) {
            Log::warning('Semaphore SMS connection failed.', ['exception' => get_class($e)]);
        }

        return 'failed';
    }
}
