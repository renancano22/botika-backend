<?php

namespace App\Services;

use App\Models\Medicine;

/** Fictional quantities for demonstrating the real forecast calculations, separate from operational data. */
class ForecastSampleData
{
    public static function make(int $months): array
    {
        // Start in the latest September that has finished. Never fabricate future actuals.
        $start = now()->startOfYear()->month(9)->startOfMonth();
        if ($start->copy()->addMonth()->greaterThan(now()->startOfMonth())) {
            $start->subYear();
        }
        $window = now()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $empty = [];
        for ($date = $window->copy(); $date->lessThanOrEqualTo(now()->startOfMonth()); $date->addMonthNoOverflow()) {
            $empty[$date->format('Y-m')] = 0;
        }

        // September totals: 60, 40, 30. October: 90, 40, 25. Rising, steady and falling demand.
        $definitions = [
            [-1, 'Paracetamol 500mg (sample)', 'Analgesic', 60, 30, 45],
            [-2, 'Cetirizine 10mg (sample)', 'Antihistamine', 40, 0, 180],
            [-3, 'Ascorbic Acid 500mg (sample)', 'Vitamins', 30, -5, 20],
        ];
        $medicines = [];
        $histories = [];
        foreach ($definitions as [$id, $name, $category, $base, $change, $stock]) {
            // Unsaved model attributes only; negative IDs cannot be confused with operational medicines.
            $medicine = new Medicine();
            $medicine->forceFill([
                'medicine_id' => $id, 'medicine_name' => $name, 'category' => $category,
                'unit' => 'tablet', 'reorder_level' => 10, 'available_stock' => $stock,
                'stock_verified_at' => now(),
            ]);
            $medicines[] = $medicine;
            $histories[$id] = $empty;
            $offset = 0;
            for ($date = $start->copy(); $date->lessThanOrEqualTo(now()->startOfMonth()); $date->addMonthNoOverflow(), $offset++) {
                $quantity = max(5, $base + $change * $offset);
                if ($date->isSameMonth(now())) {
                    $quantity = (int) floor($quantity * now()->day / now()->daysInMonth);
                }
                $key = $date->format('Y-m');
                if (array_key_exists($key, $empty)) $histories[$id][$key] = $quantity;
            }
        }
        return [
            'first_date' => $start->toDateString(), 'medicines' => collect($medicines),
            'histories' => ['empty' => $empty, 'by_medicine' => $histories],
        ];
    }
}
