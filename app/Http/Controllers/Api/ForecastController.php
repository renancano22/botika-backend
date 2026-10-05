<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Services\ForecastService;

/** Demand Forecasting (Objectives 2.4, 3.5). */
class ForecastController extends Controller
{
    public function __construct(private ForecastService $forecast) {}

    /** Runs the forecast for all medicines, saves results, returns restocking recommendations. */
    public function index()
    {
        return [
            'generated_at' => now()->toDateTimeString(),
            'history_months' => config('botika.forecast_history_months', 12),
            'results' => $this->forecast->forecastAll(),
        ];
    }

    public function show(Medicine $medicine)
    {
        return $this->forecast->forecastMedicine($medicine);
    }
}
