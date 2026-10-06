<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\StockTransaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Inventory Management (DFD Process 2.0). */
class InventoryService
{
    public function __construct(private SmsService $sms) {}

    /** Quantity in batches that have not expired (physically on the shelf). */
    public function availableStock(int $medicineId): int
    {
        return (int) Inventory::where('medicine_id', $medicineId)
            ->whereDate('expiration_date', '>', now()->toDateString())
            ->sum('quantity');
    }

    /**
     * Quantity already promised to residents: approved medicine requests that have not
     * been dispensed yet. This stock is still on the shelf but must not be given to others.
     */
    public function reservedStock(int $medicineId): int
    {
        return (int) RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->where('requests.request_type', 'medicine')
            ->where('requests.status', 'approved')
            ->where('request_items.medicine_id', $medicineId)
            ->sum('request_items.quantity');
    }

    /** Reserved quantity for every medicine at once: [medicine_id => quantity]. */
    public function reservedByMedicine(): array
    {
        return RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->where('requests.request_type', 'medicine')
            ->where('requests.status', 'approved')
            ->groupBy('request_items.medicine_id')
            ->selectRaw('request_items.medicine_id, SUM(request_items.quantity) as reserved')
            ->pluck('reserved', 'medicine_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** Stock that can still be requested or given to walk-ins: available minus reserved. */
    public function freeStock(int $medicineId): int
    {
        return max(0, $this->availableStock($medicineId) - $this->reservedStock($medicineId));
    }

    /** Stock-in: records a new batch, logs it, then checks if waiting restock requests can now be fulfilled. */
    public function stockIn(int $medicineId, int $quantity, string $expirationDate, ?int $userId = null): Inventory
    {
        $batch = Inventory::create([
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'expiration_date' => $expirationDate,
        ]);

        $this->log($batch, 'stock_in', $quantity, $userId, 'New batch received');
        $this->fulfillRestockRequests($medicineId);

        return $batch;
    }

    /** Stock-out from a specific batch (e.g. expired or damaged items removed), with a reason. */
    public function stockOut(Inventory $batch, int $quantity, string $reason, ?int $userId = null): Inventory
    {
        if ($quantity > $batch->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Quantity is more than what is left in this batch.']);
        }
        $batch->quantity -= $quantity;
        $batch->save();

        $this->log($batch, 'stock_out', $quantity, $userId, $reason);

        return $batch;
    }

    /**
     * Deducts stock using First-Expiry-First-Out and never touches expired batches,
     * so expired medicines are never dispensed. Each batch used is logged as "dispensed".
     */
    public function deduct(int $medicineId, int $quantity, ?int $userId = null, ?int $dispensingId = null): void
    {
        $batches = Inventory::where('medicine_id', $medicineId)
            ->where('quantity', '>', 0)
            ->whereDate('expiration_date', '>', now()->toDateString())
            ->orderBy('expiration_date')
            ->lockForUpdate()
            ->get();

        if ($batches->sum('quantity') < $quantity) {
            $name = Medicine::find($medicineId)?->medicine_name ?? "Medicine #{$medicineId}";
            throw ValidationException::withMessages(['items' => "Not enough non-expired stock for {$name}."]);
        }

        foreach ($batches as $batch) {
            if ($quantity <= 0) break;
            $take = min($batch->quantity, $quantity);
            $batch->quantity -= $take;
            $batch->save();
            $quantity -= $take;

            $this->log($batch, 'dispensed', $take, $userId, null, $dispensingId);
        }
    }

    private function log(Inventory $batch, string $type, int $quantity, ?int $userId, ?string $reason, ?int $dispensingId = null): void
    {
        StockTransaction::create([
            'medicine_id' => $batch->medicine_id,
            'inventory_id' => $batch->inventory_id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'dispensing_id' => $dispensingId,
            'performed_by' => $userId,
        ]);
    }

    /** Stock Alert System: low stock, near-expiry and expired batches. */
    public function alerts(): array
    {
        $today = now()->toDateString();
        $warnUntil = now()->addDays(config('botika.expiry_warning_days', 30))->toDateString();

        $lowStock = Medicine::withAvailableStock()->get()
            ->map(function (Medicine $m) {
                $available = (int) ($m->available_stock ?? 0);
                return [
                    'medicine_id' => $m->medicine_id,
                    'medicine_name' => $m->medicine_name,
                    'unit' => $m->unit,
                    'available_stock' => $available,
                    'reorder_level' => $m->reorder_level,
                    'status' => Medicine::stockStatus($available, $m->reorder_level),
                ];
            })
            ->filter(fn ($m) => $m['status'] !== 'available')
            ->values();

        $nearExpiry = Inventory::with('medicine:medicine_id,medicine_name,unit')
            ->where('quantity', '>', 0)
            ->whereDate('expiration_date', '>', $today)
            ->whereDate('expiration_date', '<=', $warnUntil)
            ->orderBy('expiration_date')->get();

        $expired = Inventory::with('medicine:medicine_id,medicine_name,unit')
            ->where('quantity', '>', 0)
            ->whereDate('expiration_date', '<=', $today)
            ->orderBy('expiration_date')->get();

        return [
            'low_stock' => $lowStock,
            'near_expiry' => $nearExpiry,
            'expired' => $expired,
        ];
    }

    /** Approved restock requests become "fulfilled" once all their medicines are back in stock. */
    public function fulfillRestockRequests(int $medicineId): void
    {
        /** @var Collection<int, MedicineRequest> $waiting */
        $waiting = MedicineRequest::with(['items.medicine', 'resident'])
            ->where('request_type', 'restock')
            ->where('status', 'approved')
            ->whereHas('items', fn ($q) => $q->where('medicine_id', $medicineId))
            ->get();

        foreach ($waiting as $req) {
            $allAvailable = $req->items->every(fn ($item) => $this->freeStock($item->medicine_id) >= $item->quantity);
            if (! $allAvailable) continue;

            $req->update(['status' => 'fulfilled']);
            $names = $req->items->map(fn ($i) => $i->medicine->medicine_name)->implode(', ');
            $this->sms->notify(
                $req->resident,
                "BulanBotikaCare: Good news! {$names} is now available at Botika ng Bayan Bulan. You may now submit a medicine request.",
                $req->request_id
            );
        }
    }
}
