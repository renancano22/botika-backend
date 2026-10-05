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

    /**
     * Saves every mobile number in one format (09XXXXXXXXX), so "+63 917 123 4567",
     * "639171234567" and "0917-123-4567" are all treated as the same number.
     */
    public static function normalizePhone(?string $number): string
    {
        $digits = preg_replace('/\D/', '', (string) $number);
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }
        return $digits;
    }

    /** Patient ID printed on / encoded in the resident's QR code, e.g. BBC-000001. */
    public static function makePatientId(int $residentId): string
    {
        return 'BBC-' . str_pad((string) $residentId, 6, '0', STR_PAD_LEFT);
    }
}
