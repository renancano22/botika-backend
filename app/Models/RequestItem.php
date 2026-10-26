<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    protected $primaryKey = 'request_item_id';
    public $timestamps = false;

    protected $fillable = ['request_id', 'medicine_id', 'quantity', 'source'];
    protected $casts = ['quantity' => 'integer'];

    /** Asked for in the online request, or in person at the Botika ng Bayan (walk-in). */
    public const REQUEST = 'request';
    public const WALK_IN = 'walk_in';
    protected $attributes = ['source' => self::REQUEST];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
