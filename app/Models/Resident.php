<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    protected $primaryKey = 'resident_id';
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'name', 'address', 'contact_no', 'qr_code'];
    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MedicineRequest::class, 'resident_id', 'resident_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'resident_id', 'resident_id');
    }

    /** Patient ID printed on / encoded in the resident's QR code, e.g. BBC-000001. */
    public static function makePatientId(int $residentId): string
    {
        return 'BBC-' . str_pad((string) $residentId, 6, '0', STR_PAD_LEFT);
    }
}
