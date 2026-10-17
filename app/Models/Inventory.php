<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';
    protected $primaryKey = 'inventory_id';
    const CREATED_AT = null;
    const UPDATED_AT = 'last_updated';

    protected $fillable = ['medicine_id', 'quantity', 'expiration_date'];
    protected $casts = ['expiration_date' => 'date:Y-m-d', 'last_updated' => 'datetime', 'quantity' => 'integer'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
