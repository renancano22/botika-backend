<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Http\Request;

/** Medicine records (admin & staff manage; everyone can view availability). */
class MedicineController extends Controller
{
    public function index(Request $request)
    {
        $isResident = $request->user()->role === User::ROLE_RESIDENT;

        $medicines = Medicine::withAvailableStock()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('medicine_name', 'like', "%{$s}%")->orWhere('category', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('medicine_name')
            ->get();

        return $medicines->map(function (Medicine $m) use ($isResident) {
            $available = (int) ($m->available_stock ?? 0);
            $row = [
                'medicine_id' => $m->medicine_id,
                'medicine_name' => $m->medicine_name,
                'category' => $m->category,
                'unit' => $m->unit,
                'description' => $m->description,
                'available_stock' => $available,
                'status' => Medicine::stockStatus($available, $m->reorder_level),
            ];
            if (! $isResident) {
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
