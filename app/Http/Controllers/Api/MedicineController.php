<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\MedicineCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Medicine records (admin & staff manage; everyone can view availability). */
class MedicineController extends Controller
{
    public function index(Request $request, InventoryService $inventory)
    {
        $isResident = $request->user()->role === User::ROLE_RESIDENT;
        $reserved = $inventory->reservedByMedicine();
        $today = now()->toDateString();
        $warnUntil = InventoryService::nearExpiryCutoff();

        $medicines = Medicine::withAvailableStock()
            ->withMax('inventory as last_stock_update', 'last_updated')
            ->when(! $isResident, fn ($q) => $q->with(['inventory' => fn ($b) => $b->where('quantity', '>', 0)->orderBy('expiration_date')]))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('medicine_name', 'like', "%{$s}%")->orWhere('generic_name', 'like', "%{$s}%")->orWhere('category', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('generic_name')->orderBy('medicine_name')
            ->get();

        return $medicines->map(function (Medicine $m) use ($isResident, $reserved, $today, $warnUntil) {
            $onShelf = (int) ($m->available_stock ?? 0);
            $reservedQty = $reserved[$m->medicine_id] ?? 0;
            $free = max(0, $onShelf - $reservedQty);
            $recorded = $m->stockRecorded();

            $row = [
                'medicine_id' => $m->medicine_id,
                'medicine_code' => $m->code(),
                'medicine_name' => $m->medicine_name,
                'generic_name' => $m->generic_name,
                'strength' => $m->strength,
                'dosage_form' => $m->dosage_form,
                'category' => $m->category,
                'unit' => $m->unit,
                'description' => $m->description,
                'requires_prescription' => (bool) $m->requires_prescription,
                // Residents only see what they can still request (stock not reserved for approved requests).
                'available_stock' => $isResident ? $free : $onShelf,
                // "unrecorded": staff have not recorded this medicine's stock yet, so it is never shown as available.
                'stock_recorded' => $recorded,
                'status' => Medicine::stockStatus($isResident ? $free : $onShelf, $m->reorder_level, $recorded),
            ];
            if (! $isResident) {
                $row['reserved_stock'] = $reservedQty;
                $row['free_stock'] = $free;
                $row['reorder_level'] = $m->reorder_level;
                $row['created_at'] = $m->created_at;
                $last = collect([$m->updated_at, $m->created_at, $m->last_stock_update ? Carbon::parse($m->last_stock_update) : null])->filter()->max();
                $row['last_updated'] = $last?->toDateTimeString();
                $row['batches'] = $m->inventory->map(function (Inventory $b) use ($today, $warnUntil) {
                    $exp = $b->expiration_date->toDateString();
                    return [
                        'inventory_id' => $b->inventory_id,
                        'quantity' => $b->quantity,
                        'expiration_date' => $exp,
                        'expiry_status' => $exp <= $today ? 'expired' : ($exp <= $warnUntil ? 'near_expiry' : 'ok'),
                    ];
                })->values();
            }
            return $row;
        });
    }

    /** The Botika's 18 categories first, then any other category still used by a medicine. */
    public function categories()
    {
        $used = Medicine::query()->distinct()->orderBy('category')->pluck('category')->all();
        return array_values(array_unique([...MedicineCatalog::CATEGORIES, ...$used]));
    }

    /** Dosage forms and the unit each one is counted in, e.g. {"Syrup": "bottle"}. */
    public function dosageForms()
    {
        return MedicineCatalog::DOSAGE_FORMS;
    }

    public function store(Request $request)
    {
        return response()->json(Medicine::create($this->validated($request)), 201);
    }

    public function update(Request $request, Medicine $medicine)
    {
        $medicine->update($this->validated($request, $medicine));
        return $medicine;
    }

    /**
     * Staff confirm that there is no stock of a medicine whose stock was never recorded:
     * it then shows as "Out of stock" (and residents can ask for a restock) instead of "Not recorded".
     */
    public function confirmNoStock(Medicine $medicine, InventoryService $inventory)
    {
        if ($inventory->availableStock($medicine->medicine_id) > 0) {
            return response()->json(['message' => 'This medicine has stock recorded in Inventory.'], 422);
        }
        $medicine->update(['stock_verified_at' => now()]);
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

    private function validated(Request $request, ?Medicine $ignore = null): array
    {
        $request->merge([
            'generic_name' => trim(preg_replace('/\s+/', ' ', (string) $request->input('generic_name'))),
            'strength' => $request->filled('strength') ? trim(preg_replace('/\s+/', ' ', $request->input('strength'))) : null,
            'dosage_form' => $request->filled('dosage_form') ? $request->input('dosage_form') : null,
        ]);
        $data = $request->validate([
            'generic_name' => 'required|string|max:150',
            'strength' => 'nullable|string|max:50',
            'dosage_form' => ['nullable', Rule::in(array_keys(MedicineCatalog::DOSAGE_FORMS))],
            'category' => 'required|string|max:100',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
            'requires_prescription' => 'boolean',
            'reorder_level' => 'required|integer|min:0',
        ], [
            'generic_name.required' => 'Enter the generic name.',
            'dosage_form.in' => 'Choose a dosage form from the list.',
        ]);

        $name = MedicineCatalog::displayName($data['generic_name'], $data['strength'] ?? null, $data['dosage_form'] ?? null);
        $duplicate = Medicine::where('medicine_name', $name)
            ->when($ignore, fn ($q) => $q->where('medicine_id', '!=', $ignore->medicine_id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['generic_name' => "{$name} is already in the medicine list."]);
        }

        return $data;
    }
}
