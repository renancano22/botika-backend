<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Listing;
use App\Support\MedicineCatalog;
use App\Support\MedicineClassification;
use App\Support\MedicineIdentity;
use Illuminate\Database\QueryException;
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
            // ?prescription=required (needs a doctor's prescription) / not_required (no prescription needed)
            ->when(in_array($request->input('prescription'), ['required', 'not_required'], true),
                fn ($q) => $q->where('requires_prescription', $request->input('prescription') === 'required'))
            ->orderBy('generic_name')->orderBy('medicine_name')
            ->get();

        $rows = $medicines->map(function (Medicine $m) use ($isResident, $reserved, $today, $warnUntil) {
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

        // Data table (?per_page=): the stock status is worked out above, so the list is filtered by it here
        // (?status=) and then one page is sent. "unrecorded" counts every matching medicine, not only the page.
        if (Listing::wanted($request)) {
            $status = (string) $request->input('status', '');
            return Listing::paginateCollection(
                $status !== '' ? $rows->where('status', $status)->values() : $rows,
                $request,
                ['unrecorded' => $rows->where('status', 'unrecorded')->count()],
            );
        }

        return $rows;
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
        $data = $this->validated($request);
        try {
            return response()->json(Medicine::create($data), 201);
        } catch (QueryException $e) {
            // Saved by someone else at the same moment: the unique identity key refuses the second one.
            return $this->duplicateResponse($request, $data) ?? throw $e;
        }
    }

    public function update(Request $request, Medicine $medicine)
    {
        $data = $this->validated($request, $medicine);
        try {
            $medicine->update($data);
        } catch (QueryException $e) {
            return $this->duplicateResponse($request, $data, $medicine) ?? throw $e;
        }
        return $medicine;
    }

    /**
     * Checks a medicine while it is being typed in Add / Edit medicine (nothing is saved): is it already
     * registered, and does the category fit the medicine? ?generic_name= &strength= &dosage_form= &category= &ignore=
     */
    public function check(Request $request)
    {
        $generic = trim((string) $request->input('generic_name'));
        if (mb_strlen($generic) < 2) {
            return ['duplicate' => null, 'duplicate_message' => null, 'categories' => null, 'category_message' => null];
        }
        $strength = $request->input('strength') ?: null;
        $form = $request->input('dosage_form') ?: null;
        $ignore = $request->integer('ignore') ?: null;
        $duplicate = MedicineIdentity::findDuplicate($generic, $strength, $form, $ignore);
        $category = (string) $request->input('category');

        return [
            'duplicate' => $duplicate ? $this->duplicateInfo($duplicate) : null,
            'duplicate_message' => $duplicate ? MedicineIdentity::message($duplicate, $request->user()->hasPermission('inventory.manage')) : null,
            'categories' => MedicineClassification::categoriesFor($generic, $form), // null: not in the reference
            'category_message' => $category !== '' ? MedicineClassification::mismatch($generic, $form, $category) : null,
        ];
    }

    /**
     * Staff confirm that there is no stock of a medicine whose stock was never recorded:
     * it then shows as "Out of stock" (and residents can ask for a restock) instead of "Not recorded".
     */
    public function confirmNoStock(Medicine $medicine, InventoryService $inventory)
    {
        if ($inventory->availableStock($medicine->medicine_id) > 0) {
            return response()->json(['message' => 'This medicine has stock recorded in Stock & Batches.'], 422);
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
            'generic_name' => ['required', 'string', 'min:2', 'max:150', "regex:/^[\pL\pN][\pL\pN\s.,'()\/+\-]*$/u"],
            'strength' => ['nullable', 'string', 'max:50', 'regex:/^[\pN][\pL\pN\s.,\/%+\-]*$/u'],
            'dosage_form' => ['nullable', Rule::in(array_keys(MedicineCatalog::DOSAGE_FORMS))],
            'category' => ['required', 'string', 'max:100', Rule::in($this->categories())],
            'unit' => ['required', 'string', 'max:50', "regex:/^[\pL][\pL\s\-]*$/u"],
            'requires_prescription' => 'boolean',
            'reorder_level' => 'required|integer|min:0|max:100000',
        ], [
            'generic_name.required' => 'Enter the generic name.',
            'generic_name.min' => 'The generic name must have at least 2 characters.',
            'generic_name.regex' => 'The generic name can only have letters, numbers, spaces and . , \' ( ) / + -',
            'strength.regex' => 'Enter the strength starting with a number, e.g. 500mg, 100mg/5ml or 1%.',
            'dosage_form.in' => 'Choose a dosage form from the list.',
            'category.in' => 'Choose a category from the list.',
            'unit.regex' => 'The unit can only have letters, e.g. tablet, capsule, bottle.',
            'reorder_level.integer' => 'The reorder level must be a whole number.',
            'reorder_level.max' => 'The reorder level can be at most 100,000.',
        ]);

        // Duplicate medicine: the same active ingredient, strength and dosage form is already registered.
        // When editing, only a change to the medicine itself is checked (other details can always be saved).
        $identityChanged = ! $ignore || MedicineIdentity::key($data['generic_name'], $data['strength'] ?? null, $data['dosage_form'] ?? null)
            !== MedicineIdentity::key($ignore->generic_name, $ignore->strength, $ignore->dosage_form, $ignore->medicine_name);
        if ($identityChanged && ($response = $this->duplicateResponse($request, $data, $ignore))) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }

        // Category: checked against the verified classification; a confirmed mismatch is not saved.
        if ($wrong = MedicineClassification::mismatch($data['generic_name'], $data['dosage_form'] ?? null, $data['category'])) {
            throw ValidationException::withMessages(['category' => $wrong]);
        }

        return $data;
    }

    /** 422 with the warning and the existing medicine (for "Record stock-in"), or null when there is no duplicate. */
    private function duplicateResponse(Request $request, array $data, ?Medicine $ignore = null): ?\Illuminate\Http\JsonResponse
    {
        $duplicate = MedicineIdentity::findDuplicate($data['generic_name'], $data['strength'] ?? null, $data['dosage_form'] ?? null, $ignore?->medicine_id);
        if (! $duplicate) return null;
        $message = MedicineIdentity::message($duplicate, $request->user()->hasPermission('inventory.manage'));
        return response()->json([
            'message' => $message,
            'errors' => ['generic_name' => [$message]],
            'duplicate' => $this->duplicateInfo($duplicate),
        ], 422);
    }

    private function duplicateInfo(array $duplicate): array
    {
        $m = $duplicate['medicine'];
        return ['medicine_id' => $m->medicine_id, 'medicine_code' => $m->code(), 'medicine_name' => $m->medicine_name, 'exact' => $duplicate['exact']];
    }
}
