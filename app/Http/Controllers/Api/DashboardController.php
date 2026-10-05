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
use App\Services\InventoryService;
use Illuminate\Http\Request;

/** Dashboards for the administrator, pharmacy staff and residents (Objective 1). */
class DashboardController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();

        return match ($user->role) {
            User::ROLE_RESIDENT => $this->resident($user),
            default => $this->operations($user),
        };
    }

    private function operations(User $user): array
    {
        $alerts = $this->inventory->alerts();
        $today = now()->toDateString();

        $data = [
            'counts' => [
                'medicines' => Medicine::count(),
                'low_stock' => collect($alerts['low_stock'])->where('status', 'low_stock')->count(),
                'out_of_stock' => collect($alerts['low_stock'])->where('status', 'out_of_stock')->count(),
                'near_expiry' => $alerts['near_expiry']->count(),
                'expired' => $alerts['expired']->count(),
                'pending_requests' => MedicineRequest::where('request_type', 'medicine')->where('status', 'pending')->count(),
                'approved_to_dispense' => MedicineRequest::where('request_type', 'medicine')->where('status', 'approved')->count(),
                'pending_restock' => MedicineRequest::where('request_type', 'restock')->where('status', 'pending')->count(),
                'dispensed_today' => Dispensing::whereDate('dispensed_at', $today)->count(),
                'residents' => Resident::count(),
            ],
            'alerts' => $alerts,
            'recent_requests' => MedicineRequest::with(['items.medicine:medicine_id,medicine_name', 'resident:resident_id,name'])
                ->where('status', 'pending')->orderBy('request_date')->limit(8)->get(),
            'monthly_dispensing' => $this->monthlyDispensing(),
        ];

        if ($user->role === User::ROLE_ADMIN) {
            $data['counts']['staff'] = User::where('role', User::ROLE_STAFF)->where('is_active', true)->count();
            $data['top_medicines'] = DispensingItem::query()
                ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
                ->join('medicines', 'medicines.medicine_id', '=', 'dispensing_items.medicine_id')
                ->where('dispensing.dispensed_at', '>=', now()->subDays(30))
                ->groupBy('medicines.medicine_id', 'medicines.medicine_name')
                ->orderByDesc('total')
                ->selectRaw('medicines.medicine_name, SUM(dispensing_items.quantity) as total')
                ->limit(5)->get();
        }

        return $data;
    }

    /** Total quantity dispensed per month for the last 6 months (including the current month). */
    private function monthlyDispensing(): array
    {
        $start = now()->startOfMonth()->subMonths(5);
        $months = [];
        for ($d = $start->copy(); $d <= now(); $d->addMonth()) {
            $months[$d->format('Y-m')] = 0;
        }

        DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->where('dispensing.dispensed_at', '>=', $start)
            ->get(['dispensing_items.quantity', 'dispensing.dispensed_at'])
            ->each(function ($row) use (&$months) {
                $key = \Carbon\Carbon::parse($row->dispensed_at)->format('Y-m');
                if (isset($months[$key])) $months[$key] += (int) $row->quantity;
            });

        return collect($months)->map(fn ($qty, $month) => ['month' => $month, 'quantity' => $qty])->values()->all();
    }

    private function resident(User $user): array
    {
        $resident = $user->resident;
        $requests = $resident->requests();

        return [
            'resident' => $resident,
            'counts' => [
                'pending' => (clone $requests)->where('status', 'pending')->count(),
                'approved' => (clone $requests)->where('status', 'approved')->count(),
                'dispensed' => (clone $requests)->where('status', 'dispensed')->count(),
                'available_medicines' => Medicine::withAvailableStock()->get()->filter(fn ($m) => $m->available_stock > 0)->count(),
            ],
            'recent_requests' => (clone $requests)->with('items.medicine:medicine_id,medicine_name,unit')
                ->orderByDesc('request_date')->limit(5)->get(),
            'recent_notifications' => Notification::where('resident_id', $resident->resident_id)
                ->orderByDesc('sent_at')->limit(5)->get(),
        ];
    }
}
