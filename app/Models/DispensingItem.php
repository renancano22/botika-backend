<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispensingItem extends Model
{
    protected $primaryKey = 'dispensing_item_id';
    public $timestamps = false;

    protected $fillable = ['dispensing_id', 'medicine_id', 'quantity'];
    protected $casts = ['quantity' => 'integer'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
