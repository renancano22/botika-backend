<?php

namespace App\Services;

use App\Models\DispensingItem;
use App\Models\Forecast;
use App\Models\Medicine;
use Carbon\Carbon;

/**
 * Demand Forecasting (DFD Process 6.0).
 *
 * Reads historical dispensing records (D5), forecasts next month's demand per medicine with
 * three techniques named in the paper, and stores the results in the forecast table (D6):
 *   - moving_average        : 3-month simple moving average
 *   - linear_regression     : least-squares trend line over the monthly series
 *   - exponential_smoothing : time-series analysis using simple exponential smoothing
 * The method with the lowest back-tested mean absolute error (MAE) is used for the
 * restocking recommendation.
 */
class ForecastService
{
    public const METHODS = ['moving_average', 'linear_regression', 'exponential_smoothing'];

    public const MA_WINDOW = 3;
    public const SMOOTHING_ALPHA = 0.3;

    public function __construct(private InventoryService $inventory) {}

    // ---------------------------------------------------------------- techniques

    public static function movingAverage(array $series, int $window = self::MA_WINDOW): float
    {
        if (count($series) === 0) return 0.0;
        $last = array_slice($series, -$window);
        return array_sum($last) / count($last);
    }

    public static function linearRegression(array $series): float
    {
        $n = count($series);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float) $series[0];

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach (array_values($series) as $x => $y) {
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumXX += $x * $x;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;

        return max(0.0, $intercept + $slope * $n); // predict the next period (x = n)
    }

    public static function exponentialSmoothing(array $series, float $alpha = self::SMOOTHING_ALPHA): float
    {
        if (count($series) === 0) return 0.0;
        $values = array_values($series);
        $level = (float) $values[0];
        for ($i = 1; $i < count($values); $i++) {
            $level = $alpha * $values[$i] + (1 - $alpha) * $level;
        }
        return $level;
    }

    public static function predict(string $method, array $series): float
    {
        return match ($method) {
            'moving_average' => self::movingAverage($series),
            'linear_regression' => self::linearRegression($series),
            'exponential_smoothing' => self::exponentialSmoothing($series),
        };
    }

    /** One-step-ahead back-test: forecast month t from months 0..t-1, starting at t = 3. */
    public static function meanAbsoluteError(string $method, array $series): ?float
    {
        $values = array_values($series);
        $errors = [];
        for ($t = 3; $t < count($values); $t++) {
            $errors[] = abs($values[$t] - self::predict($method, array_slice($values, 0, $t)));
        }
        return count($errors) ? array_sum($errors) / count($errors) : null;
    }

    // ---------------------------------------------------------------- data

    /** Monthly dispensed quantities for the past N complete months, oldest first: ['2026-01' => 40, ...]. */
    public function monthlyHistory(int $medicineId, ?int $months = null): array
    {
        $months ??= config('botika.forecast_history_months', 12);
        $start = now()->startOfMonth()->subMonths($months);
        $end = now()->startOfMonth(); // exclude the current, incomplete month

        $series = [];
        for ($d = $start->copy(); $d < $end; $d->addMonth()) {
            $series[$d->format('Y-m')] = 0;
        }

        $rows = DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->where('dispensing_items.medicine_id', $medicineId)
            ->where('dispensing.dispensed_at', '>=', $start)
            ->where('dispensing.dispensed_at', '<', $end)
            ->get(['dispensing_items.quantity', 'dispensing.dispensed_at']);

        foreach ($rows as $row) {
            $key = Carbon::parse($row->dispensed_at)->format('Y-m');
            if (isset($series[$key])) $series[$key] += (int) $row->quantity;
        }

        return $series;
    }

    // ---------------------------------------------------------------- run

    /** Runs all methods for one medicine, saves them to the forecast table and returns a summary. */
    public function forecastMedicine(Medicine $medicine): array
    {
        $history = $this->monthlyHistory($medicine->medicine_id);
        $series = array_values($history);
        $forecastDate = now()->startOfMonth()->toDateString(); // the month being forecast (current month)

        $results = [];
        foreach (self::METHODS as $method) {
            $value = round(self::predict($method, $series), 2);
            Forecast::updateOrCreate(
                ['medicine_id' => $medicine->medicine_id, 'forecast_date' => $forecastDate, 'method' => $method],
                ['predicted_demand' => $value]
            );
            $results[$method] = ['predicted_demand' => $value, 'mae' => self::meanAbsoluteError($method, $series)];
        }

        $best = collect($results)
            ->filter(fn ($r) => $r['mae'] !== null)
            ->sortBy('mae')->keys()->first() ?? 'moving_average';

        $available = $this->inventory->availableStock($medicine->medicine_id);
        $predicted = (int) ceil($results[$best]['predicted_demand']);
        // Enough to cover next month's predicted demand and stay above the reorder level.
        $recommended = max(0, $predicted + $medicine->reorder_level - $available);

        return [
            'medicine_id' => $medicine->medicine_id,
            'medicine_name' => $medicine->medicine_name,
            'unit' => $medicine->unit,
            'forecast_date' => $forecastDate,
            'history' => $history,
            'methods' => $results,
            'best_method' => $best,
            'predicted_demand' => $predicted,
            'available_stock' => $available,
            'reorder_level' => $medicine->reorder_level,
            'recommended_restock' => $recommended,
        ];
    }

    public function forecastAll(): array
    {
        return Medicine::orderBy('medicine_name')->get()
            ->map(fn (Medicine $m) => $this->forecastMedicine($m))
            ->all();
    }
}
