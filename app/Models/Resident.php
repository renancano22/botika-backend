<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    protected $primaryKey = 'resident_id';
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'name', 'address', 'contact_no', 'qr_code', 'photo'];
    protected $casts = ['created_at' => 'datetime'];

    /** The profile picture is only sent where it is shown (own account / profile), to keep lists light. */
    protected $hidden = ['photo'];

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

    /** Builds the stored address, e.g. "Purok 3, Zone I Poblacion, Bulan, Sorsogon". */
    public static function composeAddress(?string $addressLine, string $barangay): string
    {
        return collect([trim((string) $addressLine), $barangay, 'Bulan, Sorsogon'])->filter()->implode(', ');
    }

    /**
     * Splits a stored address back into the barangay and the house no. / street / purok part
     * (for the profile form). Older addresses typed by hand may have no barangay from the list.
     * @return array{barangay: ?string, address_line: string}
     */
    public static function splitAddress(?string $address): array
    {
        $rest = preg_replace('/,?\s*Bulan\s*,?\s*Sorsogon\.?\s*$/i', '', trim((string) $address));
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $rest)), 'strlen'));

        $barangay = null;
        if ($parts && in_array(end($parts), config('botika.barangays'), true)) {
            $barangay = array_pop($parts);
        }

        return ['barangay' => $barangay, 'address_line' => implode(', ', $parts)];
    }

    /** Patient ID printed on / encoded in the resident's QR code, e.g. BBC-000001. */
    public static function makePatientId(int $residentId): string
    {
        return 'BBC-' . str_pad((string) $residentId, 6, '0', STR_PAD_LEFT);
    }
}
