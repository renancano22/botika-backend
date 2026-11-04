<?php

namespace App\Services;

use App\Models\MedicineRequest;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the administrator and pharmacy staff in the app (no SMS):
 *  - a new medicine request → everyone who reviews medicine requests (staff with "Review requests", and administrators);
 *  - a new restock request → the administrators (they review restock requests);
 *  - a request cancelled by the resident → the same people as for a new request of that kind.
 * A failure here never stops the resident's request from being saved.
 */
class StaffNotifier
{
    public function requestSubmitted(MedicineRequest $req): void
    {
        $restock = $req->request_type === 'restock';
        $this->send($req, $restock ? 'new_restock' : 'new_request',
            $restock ? 'New restock request' : 'New medicine request',
            "Request No. {$req->request_id} from {$this->who($req)}: {$req->itemsSummary()}. "
                . ($restock ? 'Waiting for your review (the resident asks for the medicine to be restocked).' : 'Waiting for review.'),
            '/requests?type=' . ($restock ? 'restock' : 'medicine') . "&status=pending&q=%23{$req->request_id}");
    }

    public function requestCancelled(MedicineRequest $req, bool $wasApproved): void
    {
        $this->send($req, 'request_cancelled', 'Request cancelled by the resident',
            "Request No. {$req->request_id} from {$this->who($req)} ({$req->itemsSummary()}) was cancelled by the resident."
                . ($wasApproved && $req->request_type === 'medicine' ? ' The medicine set aside was returned to the stock.' : ''),
            "/requests?type={$req->request_type}&status=cancelled&q=%23{$req->request_id}");
    }

    /** Active administrators, and for medicine requests also the staff who may review them. */
    public function recipients(string $requestType): Collection
    {
        return User::where('is_active', true)->whereIn('role', [User::ROLE_ADMIN, User::ROLE_STAFF])->get()
            ->filter(fn (User $u) => $u->role === User::ROLE_ADMIN || ($requestType === 'medicine' && $u->hasPermission('requests.review')))
            ->values();
    }

    private function who(MedicineRequest $req): string
    {
        $req->loadMissing('resident:resident_id,name,qr_code');
        return $req->resident ? "{$req->resident->name} ({$req->resident->qr_code})" : 'a resident';
    }

    private function send(MedicineRequest $req, string $type, string $title, string $message, string $link): void
    {
        try {
            $now = now();
            foreach ($this->recipients($req->request_type) as $user) {
                StaffNotification::create([
                    'user_id' => $user->user_id, 'type' => $type, 'title' => $title, 'message' => mb_substr($message, 0, 500),
                    'request_id' => $req->request_id, 'link' => $link, 'created_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Staff notification failed: ' . $e->getMessage());
        }
    }
}
