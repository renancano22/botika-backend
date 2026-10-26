<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispensing extends Model
{
    protected $table = 'dispensing';
    protected $primaryKey = 'dispensing_id';
    public $timestamps = false;

    protected $fillable = ['request_id', 'dispensed_by', 'dispensed_at', 'prescription_checked'];
    protected $casts = ['dispensed_at' => 'datetime', 'prescription_checked' => 'boolean'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MedicineRequest::class, 'request_id', 'request_id');
    }

    public function dispenser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispensingItem::class, 'dispensing_id', 'dispensing_id');
    }

    /** Walk-in release, online request, or an online request with medicines also asked for in person (same visit). */
    public function sourceLabel(): string
    {
        if ($this->request?->remarks === 'Walk-in') {
            return 'Walk-in';
        }
        return $this->items->contains('source', DispensingItem::WALK_IN) ? 'Online request + Walk-in' : 'Online request';
    }
}
