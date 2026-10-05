<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $primaryKey = 'medicine_id';
    const UPDATED_AT = null;

    protected $fillable = ['medicine_name', 'category', 'unit', 'description', 'reorder_level'];
    protected $casts = ['created_at' => 'datetime', 'reorder_level' => 'integer'];

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class, 'medicine_id', 'medicine_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class, 'medicine_id', 'medicine_id');
    }

    /** Adds `available_stock` = quantity in batches that are not yet expired. */
    public function scopeWithAvailableStock(Builder $query): Builder
    {
        return $query->withSum(['inventory as available_stock' => function ($q) {
            $q->whereDate('expiration_date', '>', now()->toDateString());
        }], 'quantity');
    }

    public static function stockStatus(int $available, int $reorderLevel): string
    {
        if ($available <= 0) return 'out_of_stock';
        if ($available <= $reorderLevel) return 'low_stock';
        return 'available';
    }
}
