<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Resident;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;

/** Notification System (Objective 3.3). */
class NotificationController extends Controller
{
    public function __construct(private SmsService $sms) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return Notification::with('resident:resident_id,name,contact_no')
            ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->where('resident_id', $user->resident?->resident_id))
            ->orderByDesc('sent_at')
            ->limit(300)
            ->get();
    }

    /** Resident: number of unread notifications (red badge on the bell icon). */
    public function unreadCount(Request $request)
    {
        return ['unread' => $this->own($request)->whereNull('read_at')->count()];
    }

    /** Resident: open one notification (marks it as read) with the request it is about. */
    public function show(Request $request, Notification $notification)
    {
        abort_unless((int) $notification->resident_id === (int) $request->user()->resident?->resident_id, 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return [
            'notification' => $notification,
            'request' => $notification->request_id
                ? \App\Models\MedicineRequest::with(RequestController::DETAILS)->find($notification->request_id)
                : null,
        ];
    }

    /** Resident: mark one notification as read. */
    public function markRead(Request $request, Notification $notification)
    {
        abort_unless((int) $notification->resident_id === (int) $request->user()->resident?->resident_id, 404);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    /** Resident: mark all of their notifications as read. */
    public function markAllRead(Request $request)
    {
        $this->own($request)->whereNull('read_at')->update(['read_at' => now()]);

        return ['unread' => 0];
    }

    private function own(Request $request)
    {
        return Notification::where('resident_id', $request->user()->resident?->resident_id);
    }

    /** Admin: Send SMS alerts / announcements to residents (all, or selected ones). */
    public function announce(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:300',
            'resident_ids' => 'nullable|array',
            'resident_ids.*' => 'exists:residents,resident_id',
        ]);

        $residents = empty($data['resident_ids'])
            ? Resident::all()
            : Resident::whereIn('resident_id', $data['resident_ids'])->get();

        $results = $residents->map(fn (Resident $r) => $this->sms->notify($r, "BulanBotikaCare: {$data['message']}"));

        return [
            'total' => $results->count(),
            'sent' => $results->where('status', 'sent')->count(),
            'failed' => $results->where('status', 'failed')->count(),
            'logged' => $results->where('status', 'logged')->count(),
        ];
    }
}
