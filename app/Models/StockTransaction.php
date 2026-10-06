<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransaction extends Model
{
    protected $primaryKey = 'transaction_id';
    const UPDATED_AT = null;

    protected $fillable = ['medicine_id', 'inventory_id', 'type', 'quantity', 'reason', 'dispensing_id', 'performed_by'];
    protected $casts = ['quantity' => 'integer', 'created_at' => 'datetime'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id', 'inventory_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
