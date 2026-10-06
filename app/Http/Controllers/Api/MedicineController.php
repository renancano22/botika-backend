<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Http\Request;

/** Medicine records (admin & staff manage; everyone can view availability). */
class MedicineController extends Controller
{
    public function index(Request $request, InventoryService $inventory)
    {
        $isResident = $request->user()->role === User::ROLE_RESIDENT;
        $reserved = $inventory->reservedByMedicine();

        $medicines = Medicine::withAvailableStock()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('medicine_name', 'like', "%{$s}%")->orWhere('category', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('medicine_name')
            ->get();

        return $medicines->map(function (Medicine $m) use ($isResident, $reserved) {
            $onShelf = (int) ($m->available_stock ?? 0);
            $reservedQty = $reserved[$m->medicine_id] ?? 0;
            $free = max(0, $onShelf - $reservedQty);

            $row = [
                'medicine_id' => $m->medicine_id,
                'medicine_name' => $m->medicine_name,
                'category' => $m->category,
                'unit' => $m->unit,
                'description' => $m->description,
                // Residents only see what they can still request (stock not reserved for approved requests).
                'available_stock' => $isResident ? $free : $onShelf,
                'status' => Medicine::stockStatus($isResident ? $free : $onShelf, $m->reorder_level),
            ];
            if (! $isResident) {
                $row['reserved_stock'] = $reservedQty;
                $row['free_stock'] = $free;
                $row['reorder_level'] = $m->reorder_level;
                $row['created_at'] = $m->created_at;
            }
            return $row;
        });
    }

    public function categories()
    {
        return Medicine::query()->distinct()->orderBy('category')->pluck('category');
    }

    public function store(Request $request)
    {
        return response()->json(Medicine::create($this->validated($request)), 201);
    }

    public function update(Request $request, Medicine $medicine)
    {
        $medicine->update($this->validated($request));
        return $medicine;
    }

    public function destroy(Medicine $medicine)
    {
        if ($medicine->inventory()->where('quantity', '>', 0)->exists()) {
            return response()->json(['message' => 'This medicine still has stock. Stock-out its batches first.'], 422);
        }
        // Deleting would also erase its request and dispensing history, which reports and forecasting need.
        $hasHistory = \App\Models\RequestItem::where('medicine_id', $medicine->medicine_id)->exists()
            || \App\Models\DispensingItem::where('medicine_id', $medicine->medicine_id)->exists();
        if ($hasHistory) {
            return response()->json(['message' => 'This medicine already has request or dispensing records, so it cannot be deleted. Keep it to preserve the history.'], 422);
        }
        $medicine->delete();
        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'medicine_name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
            'reorder_level' => 'required|integer|min:0',
        ]);
    }
}
