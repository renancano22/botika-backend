<?php

namespace App\Models;

use App\Support\MedicineCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $primaryKey = 'medicine_id';

    protected $fillable = [
        'medicine_name', 'generic_name', 'strength', 'dosage_form', 'category', 'unit', 'description',
        'requires_prescription', 'reorder_level', 'stock_verified_at',
    ];
    protected $casts = [
        'created_at' => 'datetime', 'updated_at' => 'datetime', 'stock_verified_at' => 'datetime',
        'reorder_level' => 'integer', 'requires_prescription' => 'boolean',
    ];

    protected static function booted(): void
    {
        // medicine_name is the name shown everywhere: "Generic Strength Dosage form", e.g. "Amoxicillin 500mg Capsule".
        static::saving(function (Medicine $m) {
            if (blank($m->generic_name)) {
                $m->generic_name = $m->medicine_name; // a medicine added with a name only
            } else {
                $m->medicine_name = MedicineCatalog::displayName($m->generic_name, $m->strength, $m->dosage_form);
            }
        });
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class, 'medicine_id', 'medicine_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class, 'medicine_id', 'medicine_id');
    }

    /**
     * Adds `available_stock` = quantity in batches that are not yet expired, and `has_batches`
     * (any stock batch ever recorded) to tell "no stock" from "stock not recorded yet".
     */
    public function scopeWithAvailableStock(Builder $query): Builder
    {
        return $query->withSum(['inventory as available_stock' => function ($q) {
            $q->whereDate('expiration_date', '>', now()->toDateString());
        }], 'quantity')->withExists('inventory as has_batches');
    }

    /**
     * Stock is "recorded" once staff record a stock-in (a batch) or confirm that there is no stock.
     * Until then the medicine is never shown as available.
     */
    public function stockRecorded(): bool
    {
        return $this->stock_verified_at !== null || (bool) ($this->has_batches ?? $this->inventory()->exists());
    }

    public static function stockStatus(int $available, int $reorderLevel, bool $recorded = true): string
    {
        if (! $recorded) return 'unrecorded';
        if ($available <= 0) return 'out_of_stock';
        if ($available <= $reorderLevel) return 'low_stock';
        return 'available';
    }

    public function status(int $available): string
    {
        return self::stockStatus($available, $this->reorder_level, $this->stockRecorded());
    }

    /** "MED-0007" */
    public function code(): string
    {
        return self::codeFor((int) $this->medicine_id);
    }

    /** Medicine ID shown to users: MED-0005. */
    public static function codeFor(int $medicineId): string
    {
        return 'MED-' . str_pad((string) $medicineId, 4, '0', STR_PAD_LEFT);
    }
}
