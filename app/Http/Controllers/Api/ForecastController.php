<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForecastRun;
use App\Models\Medicine;
use App\Services\ForecastService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Demand Forecasting (Objectives 2.4, 3.5). */
class ForecastController extends Controller
{
    public function __construct(private ForecastService $forecast) {}

    /**
     * Forecast for every medicine with the chosen settings: summary cards, top barangays and medicines,
     * predicted demand and restocking recommendations. ?months=6|12|24 &horizon=1|3|6 &method=...
     */
    public function index(Request $request)
    {
        return $this->forecast->analyze(...$this->options($request)) + ['last_generated' => $this->lastGenerated()];
    }

    /**
     * "Generate Forecast": same as index, and the forecasts are saved to the forecast table (D6). Each generation is
     * recorded (when, by whom, method, history and period) so the page can show "Forecast last generated on …".
     */
    public function generate(Request $request)
    {
        $data = $this->forecast->analyze(...$this->options($request));
        $this->forecast->save($data['results'], $data['forecast_keys']);
        ForecastRun::create([
            'generated_by' => $request->user()->user_id,
            'method' => $data['method'],
            'history_months' => $data['history_months'],
            'horizon' => $data['horizon'],
            'period_start' => $data['forecast_keys'][0] . '-01',
            'period_end' => end($data['forecast_keys']) . '-01',
            'medicines' => count($data['results']),
            'generated_at' => now(),
        ]);
        return $data + ['saved' => true, 'last_generated' => $this->lastGenerated()];
    }

    /** The most recent "Generate Forecast" (null when a forecast was never generated). */
    private function lastGenerated(): ?array
    {
        $run = ForecastRun::with('generator:user_id,name')->orderByDesc('generated_at')->orderByDesc('forecast_run_id')->first();
        return $run ? [
            'generated_at' => $run->generated_at->toDateTimeString(),
            'generated_by' => $run->generator?->name,
            'method' => $run->method,
            'history_months' => $run->history_months,
            'horizon' => $run->horizon,
            'period_start' => $run->period_start->format('Y-m'),
            'period_end' => $run->period_end->format('Y-m'),
            'medicines' => $run->medicines,
        ] : null;
    }

    /** Seasonal demand: medicines given out most in the rainy and dry seasons, from the dispensing records. */
    public function seasonal()
    {
        return $this->forecast->seasonal();
    }

    public function show(Medicine $medicine)
    {
        return $this->forecast->forecastMedicine($medicine);
    }

    /** @return array{0: int, 1: int, 2: ?string} */
    private function options(Request $request): array
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', Rule::in(ForecastService::HISTORY_OPTIONS)],
            'horizon' => ['nullable', 'integer', Rule::in(ForecastService::HORIZON_OPTIONS)],
            'method' => ['nullable', 'string', Rule::in(ForecastService::METHODS)],
        ]);

        return [
            (int) ($data['months'] ?? config('botika.forecast_history_months', 12)),
            (int) ($data['horizon'] ?? ForecastService::DEFAULT_HORIZON),
            $data['method'] ?? null,
        ];
    }
}
