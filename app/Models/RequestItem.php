<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    protected $primaryKey = 'request_item_id';
    public $timestamps = false;

    protected $fillable = ['request_id', 'medicine_id', 'quantity', 'source', 'review_status', 'rejection_reason'];
    protected $casts = ['quantity' => 'integer'];

    /** Asked for in the online request, or in person at the Botika ng Bayan (walk-in). */
    public const REQUEST = 'request';
    public const WALK_IN = 'walk_in';
    protected $attributes = ['source' => self::REQUEST];

    /** Rejected lines remain in the audit record but never reserve stock or count as demand. */
    public function scopeEligible($query)
    {
        return $query->where(fn ($q) => $q->whereNull('request_items.review_status')->orWhere('request_items.review_status', '!=', 'rejected'));
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id', 'medicine_id');
    }
}
