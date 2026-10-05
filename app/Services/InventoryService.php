<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Inventory Management (DFD Process 2.0). */
class InventoryService
{
    public function __construct(private SmsService $sms) {}

    /** Quantity in batches that have not expired. */
    public function availableStock(int $medicineId): int
    {
        return (int) Inventory::where('medicine_id', $medicineId)
            ->whereDate('expiration_date', '>', now()->toDateString())
            ->sum('quantity');
    }

    /** Stock-in: records a new batch, then checks if waiting restock requests can now be fulfilled. */
    public function stockIn(int $medicineId, int $quantity, string $expirationDate): Inventory
    {
        $batch = Inventory::create([
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'expiration_date' => $expirationDate,
        ]);

        $this->fulfillRestockRequests($medicineId);

        return $batch;
    }

    /** Stock-out from a specific batch (e.g. expired or damaged items removed). */
    public function stockOut(Inventory $batch, int $quantity): Inventory
    {
        if ($quantity > $batch->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Quantity is more than what is left in this batch.']);
        }
        $batch->quantity -= $quantity;
        $batch->save();

        return $batch;
    }

    /**
     * Deducts stock using First-Expiry-First-Out and never touches expired batches,
     * so expired medicines are never dispensed.
     */
    public function deduct(int $medicineId, int $quantity): void
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
        }
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
    protected function fulfillRestockRequests(int $medicineId): void
    {
        /** @var Collection<int, MedicineRequest> $waiting */
        $waiting = MedicineRequest::with(['items.medicine', 'resident'])
            ->where('request_type', 'restock')
            ->where('status', 'approved')
            ->whereHas('items', fn ($q) => $q->where('medicine_id', $medicineId))
            ->get();

        foreach ($waiting as $req) {
            $allAvailable = $req->items->every(fn ($item) => $this->availableStock($item->medicine_id) >= $item->quantity);
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
