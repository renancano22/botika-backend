<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "Generate Forecast": when, by whom and with which settings the forecast (D6) was saved. */
class ForecastRun extends Model
{
    protected $primaryKey = 'forecast_run_id';
    public $timestamps = false;

    protected $fillable = ['generated_by', 'method', 'history_months', 'horizon', 'period_start', 'period_end', 'medicines', 'generated_at'];
    protected $casts = ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'generated_at' => 'datetime'];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by', 'user_id');
    }
}
