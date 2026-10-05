<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    protected $primaryKey = 'request_item_id';
    public $timestamps = false;

    protected $fillable = ['request_id', 'medicine_id', 'quantity'];
    protected $casts = ['quantity' => 'integer'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
