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

    public function dispensing(): BelongsTo
    {
        return $this->belongsTo(Dispensing::class, 'dispensing_id', 'dispensing_id');
    }

    /** Relations needed by reasonText() (load them to avoid one query per row). */
    public const REASON_RELATIONS = ['dispensing:dispensing_id,request_id', 'dispensing.request:request_id,resident_id,remarks', 'dispensing.request.resident:resident_id,name,qr_code'];

    /**
     * The reason shown in the stock history. Medicine given to a resident reads e.g.
     * "Dispensed to Juan Dela Cruz (BBC-000004) · Request No. 47" or "Walk-in: dispensed to …".
     */
    public function reasonText(): ?string
    {
        if ($this->type !== 'dispensed') {
            return $this->reason;
        }
        $request = $this->dispensing?->request;
        $resident = $request?->resident;
        if (! $resident) {
            return $this->reason ?? 'Dispensed to a resident';
        }
        $who = "{$resident->name} ({$resident->qr_code})";

        return $request->remarks === 'Walk-in'
            ? "Walk-in: dispensed to {$who}"
            : "Dispensed to {$who} · Request No. {$request->request_id}";
    }
}
