<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** REQUESTS entity (named MedicineRequest to avoid clashing with Laravel's Request class). */
class MedicineRequest extends Model
{
    protected $table = 'requests';
    protected $primaryKey = 'request_id';
    public $timestamps = false;

    protected $fillable = ['resident_id', 'request_type', 'request_date', 'status', 'reviewed_by', 'reviewed_at', 'remarks'];
    protected $casts = ['request_date' => 'datetime', 'reviewed_at' => 'datetime'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id', 'request_id');
    }

    public function dispensing(): HasOne
    {
        return $this->hasOne(Dispensing::class, 'request_id', 'request_id');
    }
}
