<?php

namespace App\Services;

use App\Models\DispensingItem;
use App\Models\Forecast;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\RequestItem;
use App\Models\Resident;
use Carbon\Carbon;

/**
 * Demand Forecasting (DFD Process 6.0).
 *
 * Reads historical dispensing records (D5), adds up the quantity given out per medicine per calendar
 * month, forecasts the coming months with the three techniques named in the paper, and stores the
 * results in the forecast table (D6):
 *   - moving_average        : average of the last 3 months (fewer while there are fewer months)
 *   - linear_regression     : least-squares trend line over the monthly quantities, extended forward
 *   - exponential_smoothing : time-series analysis using simple exponential smoothing (alpha 0.3)
 *
 * Which months are used:
 *   - months before the first dispensing record in the system are "no data yet", not zero demand;
 *   - months since then with nothing dispensed are a confirmed 0;
 *   - this month is still in progress, so the methods learn from complete months only. While there is
 *     no complete month yet, this month so far is used as a provisional basis.
 * A method that cannot give a meaningful forecast with the months available returns null (no made-up
 * numbers), and forecasts based on fewer months than recommended are marked provisional.
 * Each method is back-tested on the same records (mean absolute error, MAE) so the admin can see which
 * one has been the most accurate. The chosen method drives the restocking recommendation.
 */
class ForecastService
{
    public const METHODS = ['moving_average', 'linear_regression', 'exponential_smoothing'];

    /** "Last 6 / 12 / 24 months" of records and "Next 1 / 3 / 6 months" to forecast. */
    public const HISTORY_OPTIONS = [6, 12, 24];
    public const HORIZON_OPTIONS = [1, 3, 6];
    public const DEFAULT_HORIZON = 3;

    public const MA_WINDOW = 3;
    public const SMOOTHING_ALPHA = 0.3;

    /**
     * Months of records a method needs before it can forecast at all. A trend line needs 3 months:
     * a straight line always passes exactly through 2 points, so 2 months cannot show a real trend.
     */
    public const MIN_MONTHS = ['moving_average' => 1, 'linear_regression' => 3, 'exponential_smoothing' => 1];

    /** Months of records before a method's forecast is no longer provisional. */
    public const RECOMMENDED_MONTHS = ['moving_average' => 3, 'linear_regression' => 6, 'exponential_smoothing' => 3];

    /** Back-testing (the "most accurate" check) starts at month 4: months 1-3 are used to forecast it. */
    public const BACKTEST_START = 3;

    public static function canForecast(string $method, int $months): bool
    {
        return $months >= self::MIN_MONTHS[$method];
    }

    // ---------------------------------------------------------------- techniques (next month)

    public static function movingAverage(array $series, int $window = self::MA_WINDOW): float
    {
        if (count($series) === 0) return 0.0;
        $last = array_slice(array_values($series), -$window);
        return array_sum($last) / count($last);
    }

    /** @return array{0: float, 1: float} [slope, intercept] of the least-squares line through (0, y0), (1, y1), ... */
    private static function regressionLine(array $values): array
    {
        $n = count($values);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach (array_values($values) as $x => $y) {
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumXX += $x * $x;
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        return [$slope, $intercept];
    }

    /** Next month on the trend line, or null with fewer than 3 months (no trend can be calculated). */
    public static function linearRegression(array $series): ?float
    {
        $values = array_map('floatval', array_values($series));
        $n = count($values);
        if (! self::canForecast('linear_regression', $n)) return null;

        [$slope, $intercept] = self::regressionLine($values);
        return max(0.0, $intercept + $slope * $n); // predict the next period (x = n)
    }

    /** Smoothed level after the last month: L1 = y1, Lt = alpha * yt + (1 - alpha) * L(t-1). */
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

    public static function predict(string $method, array $series): ?float
    {
        if (! self::canForecast($method, count($series))) return null;
        return match ($method) {
            'moving_average' => self::movingAverage($series),
            'linear_regression' => self::linearRegression($series),
            'exponential_smoothing' => self::exponentialSmoothing($series),
        };
    }

    // ---------------------------------------------------------------- techniques (several months)

    /**
     * Forecast for each of the next $horizon months, or null when the method cannot forecast with these months.
     *  - moving average: the average of the last 3 actual months, the same for every coming month;
     *  - linear regression: the trend line is extended month by month (never below 0);
     *  - exponential smoothing: the smoothed level is the forecast for every coming month.
     * @return float[]|null
     */
    public static function forecastSeries(string $method, array $series, int $horizon): ?array
    {
        $values = array_map('floatval', array_values($series));
        $n = count($values);
        if (! self::canForecast($method, $n)) return null;
        if ($horizon < 1) return [];

        if ($method === 'linear_regression') {
            [$slope, $intercept] = self::regressionLine($values);
            $out = [];
            for ($h = 0; $h < $horizon; $h++) {
                $out[] = max(0.0, $intercept + $slope * ($n + $h));
            }
            return $out;
        }

        $value = $method === 'exponential_smoothing' ? self::exponentialSmoothing($values) : self::movingAverage($values);
        return array_fill(0, $horizon, $value);
    }

    /**
     * The numbers behind a forecast, so the page can show how it was calculated.
     * $series is keyed by month ('2026-10' => 17). Null when the method cannot forecast with these months.
     */
    public static function calculation(string $method, array $series, int $horizon): ?array
    {
        $keys = array_map('strval', array_keys($series));
        $values = array_map('floatval', array_values($series));
        $n = count($values);
        if (! self::canForecast($method, $n)) return null;

        if ($method === 'moving_average') {
            $k = min(self::MA_WINDOW, $n);
            return [
                'window' => $k,
                'months' => array_slice($keys, -$k),
                'values' => array_slice($values, -$k),
                'average' => round(self::movingAverage($values), 2),
            ];
        }

        if ($method === 'linear_regression') {
            [$slope, $intercept] = self::regressionLine($values);
            $mean = array_sum($values) / $n;
            // "Flat" when the line changes by less than 5% of the average (or half a unit) per month.
            $trend = abs($slope) < max(0.5, 0.05 * $mean) ? 'flat' : ($slope > 0 ? 'up' : 'down');
            $belowZero = false;
            for ($h = 0; $h < $horizon; $h++) {
                if ($intercept + $slope * ($n + $h) < 0) $belowZero = true;
            }
            return [
                'months' => $keys,
                'values' => $values,
                'slope' => round($slope, 2),
                'intercept' => round($intercept, 2),
                'average' => round($mean, 2),
                'trend' => $trend,
                'below_zero' => $belowZero, // part of the line fell below 0 and was set to 0
            ];
        }

        $alpha = self::SMOOTHING_ALPHA;
        $level = $values[0];
        $steps = [['month' => $keys[0], 'actual' => $values[0], 'level' => round($level, 2)]];
        for ($i = 1; $i < $n; $i++) {
            $level = $alpha * $values[$i] + (1 - $alpha) * $level;
            $steps[] = ['month' => $keys[$i], 'actual' => $values[$i], 'level' => round($level, 2)];
        }
        return ['alpha' => $alpha, 'steps' => $steps, 'level' => round($level, 2)];
    }

    /** One-step-ahead back-test: forecast month t from months 0..t-1, starting at t = 3. */
    public static function meanAbsoluteError(string $method, array $series): ?float
    {
        $values = array_values($series);
        $errors = [];
        for ($t = self::BACKTEST_START; $t < count($values); $t++) {
            $predicted = self::predict($method, array_slice($values, 0, $t));
            if ($predicted !== null) $errors[] = abs($values[$t] - $predicted);
        }
        return count($errors) ? array_sum($errors) / count($errors) : null;
    }

    // ---------------------------------------------------------------- data

    /** ['2026-04', '2026-05', ...]: $count months starting at $start. */
    private static function monthKeys(Carbon $start, int $count): array
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $start->copy()->addMonthsNoOverflow($i)->format('Y-m');
        }
        return $keys;
    }

    /**
     * First day of the oldest month of records used. The last $months months include the current
     * month (counted so far), so medicines dispensed this month already show and are used.
     */
    private static function historyStart(int $months): Carbon
    {
        return now()->startOfMonth()->subMonthsNoOverflow(max(1, $months) - 1);
    }

    /** The forecast starts with next month. */
    private static function forecastStart(): Carbon
    {
        return now()->startOfMonth()->addMonthNoOverflow();
    }

    /** Date of the very first dispensing record in the system ('2026-10-05'), or null when there is none yet. */
    public static function firstRecordDate(): ?string
    {
        $first = DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->min('dispensing.dispensed_at');
        return $first ? Carbon::parse($first)->toDateString() : null;
    }

    /** Month of the very first dispensing record in the system ('2026-10'), or null when there is none yet. */
    public static function firstRecordMonth(): ?string
    {
        $date = self::firstRecordDate();
        return $date ? substr($date, 0, 7) : null;
    }

    /**
     * Monthly dispensed quantities of each medicine for the last $months months (up to this month), oldest first,
     * read with one query: [medicine_id => ['2026-01' => 40, ...]].
     */
    public function monthlyHistories(int $months, ?array $medicineIds = null): array
    {
        $start = self::historyStart($months);
        $end = self::forecastStart();
        $empty = array_fill_keys(self::monthKeys($start, $months), 0);

        $rows = DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->when($medicineIds !== null, fn ($q) => $q->whereIn('dispensing_items.medicine_id', $medicineIds))
            ->where('dispensing.dispensed_at', '>=', $start)
            ->where('dispensing.dispensed_at', '<', $end)
            ->get(['dispensing_items.medicine_id', 'dispensing_items.quantity', 'dispensing.dispensed_at']);

        $histories = [];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->dispensed_at)->format('Y-m');
            $histories[$row->medicine_id] ??= $empty;
            if (isset($histories[$row->medicine_id][$key])) {
                $histories[$row->medicine_id][$key] += (int) $row->quantity;
            }
        }

        return ['empty' => $empty, 'by_medicine' => $histories];
    }

    /** Monthly dispensed quantities for one medicine: ['2026-01' => 40, ...]. */
    public function monthlyHistory(int $medicineId, ?int $months = null): array
    {
        $months ??= config('botika.forecast_history_months', 12);
        $h = $this->monthlyHistories($months, [$medicineId]);
        return $h['by_medicine'][$medicineId] ?? $h['empty'];
    }

    /** Barangays with the most approved medicine requests since $since (top 5). */
    public function topBarangays(Carbon $since, int $limit = 5): array
    {
        return MedicineRequest::query()
            ->join('residents', 'residents.resident_id', '=', 'requests.resident_id')
            ->where('requests.request_type', 'medicine')
            ->whereIn('requests.status', ['approved', 'dispensed'])
            ->where('requests.request_date', '>=', $since)
            ->pluck('residents.address')
            ->map(fn ($address) => Resident::splitAddress($address)['barangay'])
            ->filter()
            ->countBy()
            ->map(fn ($total, $barangay) => ['barangay' => $barangay, 'total' => $total])
            ->sortBy([['total', 'desc'], ['barangay', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /** Medicines asked for most often in medicine requests (pending and approved) since $since (top 5). */
    public function topRequestedMedicines(Carbon $since, int $limit = 5): array
    {
        return RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'request_items.medicine_id')
            ->where('requests.request_type', 'medicine')
            ->whereIn('requests.status', ['pending', 'approved', 'dispensed'])
            ->where('requests.request_date', '>=', $since)
            ->groupBy('request_items.medicine_id', 'medicines.medicine_name', 'medicines.unit')
            ->selectRaw('request_items.medicine_id, medicines.medicine_name, medicines.unit, COUNT(*) as total, SUM(request_items.quantity) as quantity')
            ->orderByDesc('total')
            ->orderBy('medicines.medicine_name')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'medicine_id' => (int) $r->medicine_id,
                'medicine_name' => $r->medicine_name,
                'unit' => $r->unit,
                'total' => (int) $r->total,
                'quantity' => (int) $r->quantity,
            ])
            ->all();
    }

    // ---------------------------------------------------------------- run

    /** One method's forecast for one series (null when the method cannot forecast with these months). */
    private static function methodResult(string $method, array $series, int $horizon, bool $withError = true): ?array
    {
        $monthly = self::forecastSeries($method, $series, $horizon);
        if ($monthly === null) return null;
        $monthly = array_map(fn ($v) => round($v, 2), $monthly);
        $mae = $withError ? self::meanAbsoluteError($method, $series) : null;

        return [
            'predicted_demand' => round(array_sum($monthly), 2),
            'monthly' => $monthly,
            'mae' => $mae === null ? null : round($mae, 2),
            'calculation' => self::calculation($method, $series, $horizon),
        ];
    }

    /**
     * Forecasts every medicine (or only $medicineIds) with all three methods.
     */
    private function compute(int $months, int $horizon, ?array $medicineIds = null): array
    {
        $historyKeys = self::monthKeys(self::historyStart($months), $months);
        $forecastKeys = self::monthKeys(self::forecastStart(), $horizon);
        $histories = $this->monthlyHistories($months, $medicineIds);
        $current = now()->format('Y-m');

        // Months before the system's first dispensing record are "no data yet", not "zero demand".
        $firstDate = self::firstRecordDate();
        $first = $firstDate === null ? null : substr($firstDate, 0, 7);
        $dataStart = $first === null ? null : max($first, $historyKeys[0]);
        $recorded = $dataStart === null ? [] : array_values(array_filter($historyKeys, fn ($k) => $k >= $dataStart));

        // The methods learn from complete months. This month is still in progress, so it is only used
        // (as a provisional basis) while there is no complete month of records yet.
        $complete = array_values(array_filter($recorded, fn ($k) => $k < $current));
        $basis = $complete ?: $recorded;
        $partial = $recorded !== [] && $complete === [];
        $n = count($basis);

        $medicines = Medicine::withAvailableStock()
            ->when($medicineIds !== null, fn ($q) => $q->whereIn('medicine_id', $medicineIds))
            ->orderBy('medicine_name')
            ->get();

        $errorSum = array_fill_keys(self::METHODS, null);
        $overall = array_fill_keys($basis, 0); // all medicines together, per basis month
        $rows = [];
        foreach ($medicines as $m) {
            $history = $histories['by_medicine'][$m->medicine_id] ?? $histories['empty'];
            $series = array_intersect_key($history, $overall); // basis months, oldest first (0 = nothing dispensed)
            foreach ($series as $key => $qty) $overall[$key] += $qty;

            $methods = [];
            foreach (self::METHODS as $method) {
                $methods[$method] = self::methodResult($method, $series, $horizon);
                if (($methods[$method]['mae'] ?? null) !== null) {
                    $errorSum[$method] = ($errorSum[$method] ?? 0) + $methods[$method]['mae'];
                }
            }
            $best = collect($methods)->filter(fn ($r) => $r !== null && $r['mae'] !== null)->sortBy('mae')->keys()->first()
                ?? 'moving_average';

            $rows[] = [
                'medicine_id' => $m->medicine_id,
                'medicine_name' => $m->medicine_name,
                'category' => $m->category,
                'unit' => $m->unit,
                'forecast_date' => self::forecastStart()->toDateString(), // first month being forecast
                'history' => $history,
                'methods' => $methods,
                'best_method' => $best,
                'available_stock' => (int) ($m->available_stock ?? 0),
                'reorder_level' => (int) $m->reorder_level,
            ];
        }

        $count = max(1, count($rows));
        $methodError = array_map(fn ($sum) => $sum === null ? null : round($sum / $count, 2), $errorSum);

        $info = [];
        $overallMethods = [];
        foreach (self::METHODS as $method) {
            $info[$method] = [
                'available' => self::canForecast($method, $n),
                'provisional' => $partial || $n < self::RECOMMENDED_MONTHS[$method],
                'min_months' => self::MIN_MONTHS[$method],
                'recommended_months' => self::RECOMMENDED_MONTHS[$method],
            ];
            $overallMethods[$method] = self::methodResult($method, $overall, $horizon, false);
        }

        return [
            'history_keys' => $historyKeys, 'forecast_keys' => $forecastKeys, 'rows' => $rows, 'method_error' => $methodError,
            'data_start' => $dataStart, 'months_with_data' => count($recorded), 'first_record_date' => $firstDate,
            // the months the methods learned from; partial = only this month so far (not finished yet)
            'basis' => ['months' => $basis, 'complete_months' => count($complete), 'partial' => $partial],
            'methods_info' => $info,
            'overall' => ['series' => $overall, 'methods' => $overallMethods],
        ];
    }

    /** The method with the lowest average back-tested error (moving average when there is not enough data). */
    private static function mostAccurate(array $methodError): string
    {
        $known = array_filter($methodError, fn ($e) => $e !== null);
        if (! $known) return 'moving_average';
        asort($known); // stable: ties keep the order of METHODS
        return array_key_first($known);
    }

    /** Adds the chosen method's forecast and the restocking recommendation to one medicine. */
    private static function applyMethod(array $row, string $method, array $forecastKeys): array
    {
        // No forecast without records: predicted demand stays 0 (the reorder level still applies).
        $chosen = $row['methods'][$method] ?? null;
        $monthly = $chosen['monthly'] ?? array_fill(0, count($forecastKeys), 0);
        $predicted = $chosen === null ? 0 : (int) ceil(round($chosen['predicted_demand'], 2));
        $available = $row['available_stock'];
        // Enough to cover the predicted demand of the forecast period and stay above the reorder level.
        $restock = max(0, $predicted + $row['reorder_level'] - $available);

        return $row + [
            'forecast' => $forecastKeys ? array_combine($forecastKeys, $monthly) : [],
            'predicted_demand' => $predicted,
            'recommended_restock' => $restock,
            // high: the stock would run out within the forecast period; medium: it would fall below the reorder level.
            'priority' => $restock === 0 ? null : ($predicted > $available ? 'high' : 'medium'),
        ];
    }

    /** Everything the Demand Forecasting page shows. */
    public function analyze(int $months, int $horizon, ?string $method = null): array
    {
        $data = $this->compute($months, $horizon);
        $best = self::mostAccurate($data['method_error']);
        $requested = $method;
        // A method that cannot forecast with the records available is not used (the page disables it).
        if ($method === null || ! $data['methods_info'][$method]['available']) {
            $method = $best;
        }

        $results = array_map(fn ($row) => self::applyMethod($row, $method, $data['forecast_keys']), $data['rows']);
        $since = self::historyStart($months);

        return [
            'generated_at' => now()->toDateTimeString(),
            'history_months' => $months,
            'horizon' => $horizon,
            'method' => $method,
            'requested_method' => $requested,
            'best_method' => $best,
            'method_error' => $data['method_error'],
            'methods_info' => $data['methods_info'],
            'history_keys' => $data['history_keys'],
            'forecast_keys' => $data['forecast_keys'],
            'current_month' => now()->format('Y-m'),
            'today' => now()->toDateString(),
            // first month with dispensing records (null = none yet), its first date, and the months since then
            'data_start' => $data['data_start'],
            'first_record_date' => $data['first_record_date'],
            'months_with_data' => $data['months_with_data'],
            'basis' => $data['basis'],
            'overall' => $data['overall'],
            'summary' => [
                'medicines_analyzed' => count($results),
                'potential_stockouts' => count(array_filter($results, fn ($r) => $r['priority'] === 'high')),
                'restock_recommendations' => count(array_filter($results, fn ($r) => $r['recommended_restock'] > 0)),
            ],
            'top_barangays' => $this->topBarangays($since),
            'top_medicines' => $this->topRequestedMedicines($since),
            'results' => $results,
        ];
    }

    /** Saves the monthly forecasts of every method that could forecast to the forecast table (D6). */
    public function save(array $results, array $forecastKeys): void
    {
        $rows = [];
        foreach ($results as $r) {
            foreach (self::METHODS as $method) {
                $monthly = $r['methods'][$method]['monthly'] ?? null;
                if ($monthly === null) continue; // not enough records for this method: nothing is saved
                foreach ($forecastKeys as $i => $month) {
                    $rows[] = [
                        'medicine_id' => $r['medicine_id'],
                        'forecast_date' => "{$month}-01",
                        'method' => $method,
                        'predicted_demand' => $monthly[$i] ?? 0,
                    ];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Forecast::upsert($chunk, ['medicine_id', 'forecast_date', 'method'], ['predicted_demand']);
        }
    }

    /** Refreshes and saves next month's forecast for one medicine (used after dispensing). */
    public function forecastMedicine(Medicine $medicine, int $horizon = 1): array
    {
        $months = (int) config('botika.forecast_history_months', 12);
        $data = $this->compute($months, $horizon, [$medicine->medicine_id]);
        $method = $data['rows'][0]['best_method'];
        $row = self::applyMethod($data['rows'][0], $method, $data['forecast_keys']);
        $this->save([$row], $data['forecast_keys']);
        return $row;
    }
}
