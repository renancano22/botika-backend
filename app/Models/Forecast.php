<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Forecast extends Model
{
    protected $table = 'forecast';
    protected $primaryKey = 'forecast_id';
    const UPDATED_AT = null;

    protected $fillable = ['medicine_id', 'forecast_date', 'predicted_demand', 'method'];
    protected $casts = ['predicted_demand' => 'float', 'created_at' => 'datetime'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
