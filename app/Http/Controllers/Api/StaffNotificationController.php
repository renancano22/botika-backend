<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffNotification;
use Illuminate\Http\Request;

/** Administrator / pharmacy staff: their own notifications (the bell). Expiry alerts are separate (warning triangle). */
class StaffNotificationController extends Controller
{
    /** The newest 30, and how many are unread. */
    public function index(Request $request)
    {
        $mine = StaffNotification::where('user_id', $request->user()->user_id);
        return [
            'data' => (clone $mine)->orderByDesc('created_at')->orderByDesc('staff_notification_id')->limit(30)->get(),
            'unread' => (clone $mine)->whereNull('read_at')->count(),
        ];
    }

    public function unreadCount(Request $request)
    {
        return ['unread' => StaffNotification::where('user_id', $request->user()->user_id)->whereNull('read_at')->count()];
    }

    public function markRead(Request $request, StaffNotification $staffNotification)
    {
        abort_unless((int) $staffNotification->user_id === (int) $request->user()->user_id, 404);
        if (! $staffNotification->read_at) {
            $staffNotification->update(['read_at' => now()]);
        }
        return $staffNotification;
    }

    public function markAllRead(Request $request)
    {
        StaffNotification::where('user_id', $request->user()->user_id)->whereNull('read_at')->update(['read_at' => now()]);
        return ['unread' => 0];
    }
}
