<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Notification;
use App\Models\Resident;
use App\Models\User;
use App\Models\RequestItem;
use App\Services\InventoryService;
use App\Support\Listing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Dashboards for the administrator, pharmacy staff and residents (Objective 1). */
class DashboardController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();

        return match ($user->role) {
            User::ROLE_RESIDENT => $this->resident($user),
            default => $this->operations($request, $user),
        };
    }

    /**
     * Administrator / pharmacy staff dashboard. Every number is counted from the database when the page asks
     * for it (every 30 seconds). Sections follow User Permissions: staff only get the parts of the dashboard
     * whose module they may open (the administrator gets everything).
     *  - "As of now": live counts and lists (pending requests, ready for pickup, stock status, expiry).
     *  - "analytics": requests and dispensing within a period (?from= / ?to=, default: the last 6 months).
     */
    private function operations(Request $request, User $user): array
    {
        $sections = [
            'requests' => $user->hasPermission('requests.view'),
            'stock' => collect(['medicines.view', 'medicines.manage', 'inventory.view', 'inventory.manage'])->contains(fn ($p) => $user->hasPermission($p)),
            'dispensing' => $user->hasPermission('dispensing.records'),
        ];
        $counts = [
            'residents' => Resident::count(),
            'staff' => User::where('role', User::ROLE_STAFF)->where('is_active', true)->count(), // active pharmacy staff
        ];
        $data = ['sections' => $sections];

        if ($sections['stock']) {
            $alerts = $this->inventory->alerts();
            // Each medicine counted once, by its status worked out from the quantity of its non-expired batches.
            $status = Medicine::withAvailableStock()->get()->countBy(fn (Medicine $m) => $m->status((int) ($m->available_stock ?? 0)));
            $counts += [
                'medicines' => Medicine::count(),
                'available' => $status['available'] ?? 0,
                'low_stock' => $status['low_stock'] ?? 0,
                'out_of_stock' => $status['out_of_stock'] ?? 0,
                'unrecorded' => $status['unrecorded'] ?? 0, // stock not recorded yet
                'near_expiry' => $alerts['near_expiry']->count(), // batches with stock left
                'expired' => $alerts['expired']->count(),
            ];
            $data['alerts'] = $alerts;
        }

        if ($sections['requests']) {
            $counts += [
                'pending_requests' => MedicineRequest::where('request_type', 'medicine')->where('status', 'pending')->count(),
                'approved_to_dispense' => MedicineRequest::where('request_type', 'medicine')->where('status', 'approved')->count(),
                'pending_restock' => MedicineRequest::where('request_type', 'restock')->where('status', 'pending')->count(),
            ];
            $data['recent_requests'] = MedicineRequest::with(['items.medicine:medicine_id,medicine_name', 'resident:resident_id,name'])
                ->where('status', 'pending')->orderBy('request_date')->orderBy('request_id')->limit(8)->get();
            // Approved medicine requests waiting to be claimed: whose they are and until when (oldest deadline first).
            $data['ready_for_pickup'] = MedicineRequest::with([
                'items.medicine:medicine_id,medicine_name,unit',
                'resident:resident_id,name,qr_code,contact_no',
                'reviewer:user_id,name',
            ])
                ->where('request_type', 'medicine')->where('status', 'approved')
                ->orderBy('reviewed_at')->orderBy('request_id')->limit(10)->get();
        }

        if ($sections['dispensing']) {
            $counts['dispensed_today'] = Dispensing::whereDate('dispensed_at', now()->toDateString())->count();
        }

        $data['counts'] = $counts;
        if ($sections['requests'] || $sections['dispensing']) {
            $data['analytics'] = $this->analytics($request, $sections);
        }

        return $data;
    }

    /**
     * Demand analysis for a period (both days included). Counting rules:
     *  - Medicine requests: each request once (not once per medicine), by the date it was submitted, with its
     *    current status. Walk-in releases are saved as requests too (remarks "Walk-in") and are shown separately.
     *  - Requested quantity: medicine requests that count as demand (MedicineRequest::DEMAND_STATUSES:
     *    pending, approved, dispensed) — the same rule as Demand Forecasting.
     *  - Dispensed: by the date the medicine was given out. Releases = dispensing records; quantity = the
     *    quantities given (the records Demand Forecasting learns from).
     *  - Restock requests: requests for out-of-stock medicines (unmet demand), cancelled ones not counted.
     * Records are only read, never changed.
     */
    private function analytics(Request $request, array $sections): array
    {
        Listing::validateDates($request);
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now()->startOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth()->subMonths(5);
        if ($from->gt($to)) {
            $from = $to->copy(); // only "from" given, after today
        }
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $group = (int) $from->diffInDays($to) + 1 <= 62 ? 'day' : 'month'; // up to 2 months: per day, longer: per month
        $bucket = fn (string $date) => $group === 'day' ? substr($date, 0, 10) : substr($date, 0, 7);
        $within = fn ($q, string $column) => $q->whereDate($column, '>=', $fromDate)->whereDate($column, '<=', $toDate);

        // Every day / month of the period, oldest first, so days without records show as 0.
        $trend = [];
        for ($d = $from->copy(); $d->lte($to); $group === 'day' ? $d->addDay() : $d->addMonthNoOverflow()->startOfMonth()) {
            $trend[$bucket($d->toDateString())] = ['period' => $bucket($d->toDateString()), 'requests' => 0, 'releases' => 0, 'quantity' => 0];
        }

        $first = collect([
            $sections['requests'] ? MedicineRequest::where('request_type', 'medicine')->min('request_date') : null,
            $sections['dispensing'] ? Dispensing::min('dispensed_at') : null,
        ])->filter()->min();

        $add = function (string $field, $rows) use (&$trend, $bucket) {
            foreach ($rows as $row) {
                $key = $bucket((string) $row->day);
                if (isset($trend[$key])) $trend[$key][$field] += (int) $row->total;
            }
        };

        $result = [
            'period' => ['from' => $fromDate, 'to' => $toDate, 'group' => $group],
            // The first request / dispensing record in the system: earlier dates have no records yet.
            'records_since' => $first ? substr((string) $first, 0, 10) : null,
            'requests' => null,
            'dispensing' => null,
        ];

        if ($sections['requests']) {
            $medicine = fn () => $within(MedicineRequest::where('request_type', 'medicine'), 'request_date');
            $byStatus = $medicine()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
            $restock = $within(MedicineRequest::where('request_type', 'restock'), 'request_date')
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

            $result['requests'] = [
                'total' => $byStatus->sum(),
                'walk_in' => $medicine()->where('remarks', 'Walk-in')->count(),
                'by_status' => collect(['pending', 'approved', 'dispensed', 'rejected', 'cancelled', 'expired'])
                    ->mapWithKeys(fn ($s) => [$s => $byStatus[$s] ?? 0])->all(),
                'demand' => $byStatus->only(MedicineRequest::DEMAND_STATUSES)->sum(),
                'quantity_requested' => (int) $within(RequestItem::query()->join('requests', 'requests.request_id', '=', 'request_items.request_id'), 'requests.request_date')
                    ->where('requests.request_type', 'medicine')->whereIn('requests.status', MedicineRequest::DEMAND_STATUSES)
                    ->sum('request_items.quantity'),
                'restock' => ['total' => $restock->except('cancelled')->sum(), 'pending' => $restock['pending'] ?? 0],
            ];
            $add('requests', $medicine()->selectRaw('DATE(request_date) as day, COUNT(*) as total')->groupBy(DB::raw('DATE(request_date)'))->get());
        }

        if ($sections['dispensing']) {
            $released = fn () => $within(Dispensing::query(), 'dispensed_at');
            $items = fn () => $within(DispensingItem::query()->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id'), 'dispensing.dispensed_at');
            $result['dispensing'] = [
                'releases' => $released()->count(),
                'quantity' => (int) $items()->sum('dispensing_items.quantity'),
                'residents' => MedicineRequest::whereIn('request_id', $released()->select('request_id'))->distinct()->count('resident_id'),
            ];
            $add('releases', $released()->selectRaw('DATE(dispensed_at) as day, COUNT(*) as total')->groupBy(DB::raw('DATE(dispensed_at)'))->get());
            $add('quantity', $items()->selectRaw('DATE(dispensing.dispensed_at) as day, SUM(dispensing_items.quantity) as total')->groupBy(DB::raw('DATE(dispensing.dispensed_at)'))->get());
        }

        $result['trend'] = array_values($trend);
        $result['top_medicines'] = $this->topMedicines($sections, $within);

        return $result;
    }

    /**
     * Most requested medicines in the period (top 10): number of requests that include the medicine (each
     * request once), quantity requested, quantity dispensed, restock requests and the current stock.
     * Ranked by requests, then by quantity dispensed.
     */
    private function topMedicines(array $sections, callable $within): array
    {
        $requested = $sections['requests'] ? $within(RequestItem::query()->join('requests', 'requests.request_id', '=', 'request_items.request_id'), 'requests.request_date')
            ->where('requests.request_type', 'medicine')->whereIn('requests.status', MedicineRequest::DEMAND_STATUSES)
            ->groupBy('request_items.medicine_id')
            ->selectRaw('request_items.medicine_id, COUNT(DISTINCT requests.request_id) as requests, SUM(request_items.quantity) as quantity')
            ->get()->keyBy('medicine_id') : collect();
        $restock = $sections['requests'] ? $within(RequestItem::query()->join('requests', 'requests.request_id', '=', 'request_items.request_id'), 'requests.request_date')
            ->where('requests.request_type', 'restock')->where('requests.status', '!=', 'cancelled')
            ->groupBy('request_items.medicine_id')
            ->selectRaw('request_items.medicine_id, COUNT(DISTINCT requests.request_id) as total')
            ->pluck('total', 'medicine_id') : collect();
        $dispensed = $sections['dispensing'] ? $within(DispensingItem::query()->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id'), 'dispensing.dispensed_at')
            ->groupBy('dispensing_items.medicine_id')
            ->selectRaw('dispensing_items.medicine_id, SUM(dispensing_items.quantity) as total')
            ->pluck('total', 'medicine_id') : collect();

        $ids = $requested->keys()->merge($restock->keys())->merge($dispensed->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $medicines = Medicine::withAvailableStock()->whereIn('medicine_id', $ids)->get()->keyBy('medicine_id');

        return $ids->map(function ($id) use ($requested, $restock, $dispensed, $medicines) {
            $m = $medicines[$id] ?? null;
            $available = (int) ($m?->available_stock ?? 0);
            return [
                'medicine_id' => (int) $id,
                'medicine_name' => $m?->medicine_name ?? "Medicine #{$id}",
                'unit' => $m?->unit,
                'requests' => (int) ($requested[$id]->requests ?? 0),
                'quantity_requested' => (int) ($requested[$id]->quantity ?? 0),
                'quantity_dispensed' => (int) ($dispensed[$id] ?? 0),
                'restock_requests' => (int) ($restock[$id] ?? 0),
                'available_stock' => $available,
                'status' => $m ? $m->status($available) : null,
            ];
        })
            ->sortBy([['requests', 'desc'], ['quantity_dispensed', 'desc'], ['restock_requests', 'desc'], ['medicine_name', 'asc']])
            ->take(10)->values()->all();
    }

    /** Resident dashboard: only the parts the resident may open (User Permissions). */
    private function resident(User $user): array
    {
        $resident = $user->resident;
        $requests = $resident->requests();
        $sections = [
            'requests' => $user->hasPermission('requests.view'),
            'notifications' => $user->hasPermission('notifications.view'),
            'medicines' => $user->hasPermission('medicines.view'),
        ];
        $data = ['resident' => $resident, 'sections' => $sections, 'counts' => []];

        if ($sections['requests']) {
            $data['counts'] += [
                'pending' => (clone $requests)->where('status', 'pending')->count(),
                // ready to claim = approved medicine requests (approved restock requests are still waiting for stock)
                'approved' => (clone $requests)->where('request_type', 'medicine')->where('status', 'approved')->count(),
                'dispensed' => (clone $requests)->where('status', 'dispensed')->count(),
            ];
            $data['recent_requests'] = (clone $requests)->with('items.medicine:medicine_id,medicine_name,unit')
                ->orderByDesc('request_date')->limit(5)->get();
        }
        if ($sections['medicines']) {
            $data['counts']['available_medicines'] = Medicine::withAvailableStock()->get()->filter(fn ($m) => $m->available_stock > 0)->count();
        }
        if ($sections['notifications']) {
            $data['recent_notifications'] = Notification::where('resident_id', $resident->resident_id)
                ->orderByDesc('sent_at')->limit(5)->get();
        }

        return $data;
    }
}
