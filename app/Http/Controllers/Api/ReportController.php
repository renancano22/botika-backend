<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Report;
use App\Models\RequestItem;
use App\Models\StockTransaction;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reporting and Analytics (DFD Process 5.0). Report types from the Scope:
 * medicine usage, inventory status, distribution (dispensing) records,
 * most requested medicines, and medicine shortages.
 */
class ReportController extends Controller
{
    public const TYPES = [
        'usage' => 'Medicine Usage',
        'inventory' => 'Inventory Status',
        'dispensing' => 'Dispensing Records',
        'most_requested' => 'Most Requested Medicines',
        'shortages' => 'Medicine Shortages',
        'transactions' => 'Inventory Transactions (Stock-in / Stock-out)',
    ];

    public function __construct(private InventoryService $inventory) {}

    /** History of generated reports (REPORTS entity). */
    public function index()
    {
        return Report::with('generator:user_id,name')->orderByDesc('generated_at')->limit(100)->get();
    }

    public function generate(Request $request, string $type)
    {
        $request->merge(['type' => $type]);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $data['from'] ?? now()->subDays(30)->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $rows = match ($type) {
            'usage' => $this->usage($from, $to),
            'inventory' => $this->inventoryStatus(),
            'dispensing' => $this->dispensing($from, $to),
            'most_requested' => $this->mostRequested($from, $to),
            'shortages' => $this->shortages($from, $to),
            'transactions' => $this->transactions($from, $to),
        };

        $dateRange = $type === 'inventory' ? 'As of ' . now()->toDateString() : "{$from} to {$to}";

        Report::create([
            'generated_by' => $request->user()->user_id,
            'report_type' => $type,
            'date_range' => $dateRange,
            'generated_at' => now(),
        ]);

        return [
            'type' => $type,
            'title' => self::TYPES[$type],
            'date_range' => $dateRange,
            'generated_at' => now()->toDateTimeString(),
            'generated_by' => $request->user()->name,
            'rows' => $rows,
        ];
    }

    private function usage(string $from, string $to): array
    {
        return DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'dispensing_items.medicine_id')
            ->whereDate('dispensing.dispensed_at', '>=', $from)
            ->whereDate('dispensing.dispensed_at', '<=', $to)
            ->groupBy('medicines.medicine_id', 'medicines.medicine_name', 'medicines.category', 'medicines.unit')
            ->orderByDesc('total_dispensed')
            ->selectRaw('medicines.medicine_name, medicines.category, medicines.unit,
                SUM(dispensing_items.quantity) as total_dispensed,
                COUNT(DISTINCT dispensing.dispensing_id) as transactions')
            ->get()->toArray();
    }

    private function inventoryStatus(): array
    {
        $today = now()->toDateString();
        $warnUntil = now()->addDays(config('botika.expiry_warning_days', 30))->toDateString();

        return Medicine::with(['inventory' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('medicine_name')->get()
            ->map(function (Medicine $m) use ($today, $warnUntil) {
                $valid = $m->inventory->filter(fn ($b) => $b->expiration_date->toDateString() > $today);
                $available = (int) $valid->sum('quantity');
                return [
                    'medicine_name' => $m->medicine_name,
                    'category' => $m->category,
                    'unit' => $m->unit,
                    'available_stock' => $available,
                    'reorder_level' => $m->reorder_level,
                    'status' => Medicine::stockStatus($available, $m->reorder_level),
                    'near_expiry_qty' => (int) $valid->filter(fn ($b) => $b->expiration_date->toDateString() <= $warnUntil)->sum('quantity'),
                    'expired_qty' => (int) $m->inventory->filter(fn ($b) => $b->expiration_date->toDateString() <= $today)->sum('quantity'),
                    'nearest_expiry' => optional($valid->sortBy('expiration_date')->first())->expiration_date?->toDateString(),
                ];
            })->all();
    }

    private function dispensing(string $from, string $to): array
    {
        return Dispensing::with(['items.medicine', 'request.resident', 'dispenser:user_id,name'])
            ->whereDate('dispensed_at', '>=', $from)
            ->whereDate('dispensed_at', '<=', $to)
            ->orderByDesc('dispensed_at')->get()
            ->map(fn (Dispensing $d) => [
                'dispensing_id' => $d->dispensing_id,
                'dispensed_at' => $d->dispensed_at->toDateTimeString(),
                'patient_id' => $d->request->resident->qr_code,
                'resident' => $d->request->resident->name,
                'medicines' => $d->items->map(fn ($i) => "{$i->medicine->medicine_name} x{$i->quantity}")->implode(', '),
                'dispensed_by' => $d->dispenser?->name,
            ])->all();
    }

    private function mostRequested(string $from, string $to): array
    {
        return RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'request_items.medicine_id')
            ->whereDate('requests.request_date', '>=', $from)
            ->whereDate('requests.request_date', '<=', $to)
            ->where('requests.status', '!=', 'cancelled')
            ->groupBy('medicines.medicine_id', 'medicines.medicine_name', 'medicines.category')
            ->orderByDesc('times_requested')
            ->selectRaw('medicines.medicine_name, medicines.category,
                COUNT(*) as times_requested,
                SUM(request_items.quantity) as total_quantity,
                SUM(CASE WHEN requests.request_type = \'restock\' THEN 1 ELSE 0 END) as restock_requests')
            ->limit(20)
            ->get()->toArray();
    }

    private function transactions(string $from, string $to): array
    {
        $labels = ['stock_in' => 'Stock-in', 'stock_out' => 'Stock-out', 'dispensed' => 'Dispensed'];

        return StockTransaction::with(['medicine:medicine_id,medicine_name,unit', 'batch:inventory_id,expiration_date', 'performer:user_id,name'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderByDesc('created_at')
            ->orderByDesc('transaction_id')
            ->get()
            ->map(fn (StockTransaction $t) => [
                'date' => $t->created_at->toDateTimeString(),
                'type' => $labels[$t->type] ?? $t->type,
                'medicine' => $t->medicine?->medicine_name,
                'quantity' => ($t->type === 'stock_in' ? '+' : '-') . $t->quantity . ' ' . ($t->medicine?->unit ?? ''),
                'batch_no' => $t->inventory_id,
                'batch_expiry' => $t->batch?->expiration_date?->toDateString(),
                'reason' => $t->reason ?? ($t->dispensing_id ? "Dispensing #{$t->dispensing_id}" : null),
                'performed_by' => $t->performer?->name,
            ])->all();
    }

    private function shortages(string $from, string $to): array
    {
        $restockDemand = RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->where('requests.request_type', 'restock')
            ->whereDate('requests.request_date', '>=', $from)
            ->whereDate('requests.request_date', '<=', $to)
            ->groupBy('request_items.medicine_id')
            ->selectRaw('request_items.medicine_id, COUNT(*) as restock_requests')
            ->pluck('restock_requests', 'medicine_id');

        return collect($this->inventory->alerts()['low_stock'])
            ->map(fn ($m) => $m + ['restock_requests' => (int) ($restockDemand[$m['medicine_id']] ?? 0)])
            ->sortBy('available_stock')->values()->all();
    }
}
