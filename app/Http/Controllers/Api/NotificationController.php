<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Resident;
use App\Models\User;
use App\Services\SmsService;
use App\Support\Listing;
use Illuminate\Http\Request;

/** Notification System (Objective 3.3). */
class NotificationController extends Controller
{
    private const CATEGORY_LABEL = [
        'general' => null,
        'closure' => 'Pharmacy Closure',
        'hours' => 'Operating Hours',
        'distribution' => 'Medicine Distribution',
    ];

    public function __construct(private SmsService $sms) {}

    /**
     * Notifications, newest first (residents: their own, all of them).
     * Data table (?per_page=, the administrator's SMS log): one page, with ?search= (resident, mobile number or
     * message), ?status=sent|failed|logged|app, ?from= / ?to= (date sent) and ?sort=newest|oldest.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $resident = $user->role === User::ROLE_RESIDENT;
        $query = Notification::with('resident:resident_id,name,contact_no')
            ->when($resident, fn ($q) => $q->where('resident_id', $user->resident?->resident_id));

        if (Listing::wanted($request)) {
            Listing::validateDates($request);
            $search = Listing::search($request);
            $status = (string) $request->input('status', '');
            $query
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('message', 'like', Listing::like($search))
                    ->when(! $resident, fn ($x) => $x->orWhereHas('resident', fn ($r) => $r
                        ->where('name', 'like', Listing::like($search))->orWhere('contact_no', 'like', Listing::like($search))))))
                ->when($status === 'app', fn ($q) => $q->where('channel', 'app'))
                ->when(in_array($status, ['sent', 'failed', 'logged'], true), fn ($q) => $q->where('channel', '!=', 'app')->where('status', $status));
            Listing::dateRange($query, $request, 'sent_at');
            $dir = Listing::sort($request, ['newest', 'oldest']) === 'oldest' ? 'asc' : 'desc';

            return Listing::paginate($query->orderBy('sent_at', $dir)->orderBy('notification_id', $dir), $request);
        }

        return $query->orderByDesc('sent_at')->orderByDesc('notification_id')
            ->when(! $resident, fn ($q) => $q->limit(300)) // residents get all of their own notifications
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
            'category' => 'nullable|in:general,closure,hours,distribution',
            'message' => 'required|string|min:5|max:300',
            'resident_ids' => 'nullable|array',
            'resident_ids.*' => 'exists:residents,resident_id',
        ], [
            'message.required' => 'Type the announcement message.',
            'message.min' => 'The message is too short (at least 5 characters).',
            'message.max' => 'Keep the message within 300 characters.',
        ]);

        $residents = empty($data['resident_ids'])
            ? Resident::all()
            : Resident::whereIn('resident_id', $data['resident_ids'])->get();

        // closure = Temporary Pharmacy Closure, hours = Operating Hours, distribution = Medicine Distribution
        $category = $data['category'] ?? 'general';
        $label = self::CATEGORY_LABEL[$category];
        $text = $label ? "BulanBotikaCare ({$label}): {$data['message']}" : "BulanBotikaCare: {$data['message']}";
        $type = $category === 'general' ? 'announcement' : $category;

        $results = $residents->map(fn (Resident $r) => $this->sms->notify($r, $text, null, $type));

        return [
            'total' => $results->count(),
            'sent' => $results->where('status', 'sent')->count(),
            'failed' => $results->where('status', 'failed')->count(),
            'logged' => $results->where('status', 'logged')->count(),
        ];
    }
}
