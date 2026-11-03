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

    /** Resident ID printed on / encoded in the resident's QR code, e.g. BBC-000001. */
    public static function makePatientId(int $residentId): string
    {
        return 'BBC-' . str_pad((string) $residentId, 6, '0', STR_PAD_LEFT);
    }

    /** Name as compared for duplicates: capital letters, periods and extra spaces ignored ("Juan  Dela Cruz." = "juan dela cruz"). */
    public static function normalName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('.', ' ', mb_strtolower($name))));
    }

    /**
     * Residents already registered in the barangay with the same name, and with a similar name (same first and last
     * name, e.g. with or without a middle initial). The same name in the same barangay is the same person (one record,
     * one Resident ID); the same name in another barangay is a different person and is allowed.
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    public static function possibleDuplicates(string $name, string $barangay, ?int $ignoreId = null): array
    {
        $target = self::normalName($name);
        $words = explode(' ', $target);
        $first = $words[0];
        $last = end($words);

        $found = self::where('address', 'like', '%' . addcslashes($barangay, '%_\\') . ', Bulan%')
            ->where('name', 'like', '%' . addcslashes($last, '%_\\') . '%')
            ->when($ignoreId, fn ($q) => $q->where('resident_id', '!=', $ignoreId))
            ->orderBy('resident_id')
            ->get(['resident_id', 'name', 'qr_code', 'address'])
            ->filter(fn (self $r) => self::splitAddress($r->address)['barangay'] === $barangay)
            ->map(fn (self $r) => ['resident_id' => $r->resident_id, 'name' => $r->name, 'qr_code' => $r->qr_code,
                'barangay' => self::splitAddress($r->address)['barangay'], 'norm' => self::normalName($r->name)]);

        $same = $found->filter(fn ($r) => $r['norm'] === $target);
        $similar = $found->filter(function ($r) use ($first, $last, $target) {
            $w = explode(' ', $r['norm']);
            return $r['norm'] !== $target && $w[0] === $first && end($w) === $last;
        });
        $clean = fn ($c) => $c->map(fn ($r) => collect($r)->except('norm')->all())->values();

        return [$clean($same), $clean($similar)];
    }

    /** The message when a name is already registered to another resident of the same barangay (account edits). */
    public const NAME_TAKEN = 'Another resident in this barangay is already registered with this name. If this is a different person, add the middle name or suffix (e.g. Jr., Sr.) to tell them apart.';
}
