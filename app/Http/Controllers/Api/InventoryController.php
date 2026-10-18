<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\StockTransaction;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Real-time tracking of medicine stocks and expiration dates (Objective 3.1). */
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $today = now()->toDateString();
        $warnUntil = InventoryService::nearExpiryCutoff();

        return Inventory::with('medicine:medicine_id,medicine_name,generic_name,strength,dosage_form,category,unit')
            ->when($request->medicine_id, fn ($q, $id) => $q->where('medicine_id', $id))
            ->when(! $request->boolean('include_empty'), fn ($q) => $q->where('quantity', '>', 0))
            ->when($request->search, fn ($q, $s) => $q->whereHas('medicine', fn ($m) => $m->where('medicine_name', 'like', "%{$s}%")))
            ->orderBy('expiration_date')
            ->get()
            ->map(function (Inventory $b) use ($today, $warnUntil) {
                $exp = $b->expiration_date->toDateString();
                $b->setAttribute('expiry_status', $exp <= $today ? 'expired' : ($exp <= $warnUntil ? 'near_expiry' : 'ok'));
                return $b;
            });
    }

    public function stockIn(Request $request)
    {
        $data = $request->validate([
            'medicine_id' => 'required|exists:medicines,medicine_id',
            'quantity' => 'required|integer|min:1',
            'expiration_date' => 'required|date|after:today',
        ]);

        $batch = DB::transaction(function () use ($data, $request) {
            // One stock-in at a time per medicine, so a double click / resent request cannot slip in twice.
            Medicine::whereKey($data['medicine_id'])->lockForUpdate()->first();

            // The same stock-in (same medicine, quantity and expiration date, same staff) saved seconds ago
            // is a duplicate submission, not a second delivery.
            $duplicate = StockTransaction::where('type', 'stock_in')
                ->where('medicine_id', $data['medicine_id'])
                ->where('quantity', $data['quantity'])
                ->where('performed_by', $request->user()->user_id)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->whereHas('batch', fn ($q) => $q->whereDate('expiration_date', $data['expiration_date']))
                ->latest('transaction_id')
                ->first();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'medicine_id' => "This stock-in was already saved a moment ago (Batch #{$duplicate->inventory_id}), so it was not recorded twice.",
                ]);
            }

            return $this->inventory->stockIn(
                (int) $data['medicine_id'], (int) $data['quantity'], $data['expiration_date'], $request->user()->user_id
            );
        });

        return response()->json($batch->load('medicine'), 201);
    }

    public function stockOut(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
        ]);

        return DB::transaction(fn () => $this->inventory
            ->stockOut($inventory, (int) $data['quantity'], $data['reason'], $request->user()->user_id)
            ->load('medicine'));
    }

    /** Stock-in / stock-out / dispensed history (audit trail of every quantity change). */
    public function transactions(Request $request)
    {
        $request->validate([
            'type' => 'nullable|in:stock_in,stock_out,dispensed',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return StockTransaction::with(['medicine:medicine_id,medicine_name,unit', 'batch:inventory_id,expiration_date', 'performer:user_id,name', ...StockTransaction::REASON_RELATIONS])
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->medicine_id, fn ($q, $id) => $q->where('medicine_id', $id))
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('created_at')
            ->orderByDesc('transaction_id')
            ->limit(500)
            ->get()
            ->each(function (StockTransaction $t) {
                $t->setAttribute('reason_text', $t->reasonText());
                $t->unsetRelation('dispensing'); // only the text is needed (keeps the list light)
            });
    }

    public function alerts()
    {
        return $this->inventory->alerts();
    }
}
