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

    protected $fillable = ['resident_id', 'request_type', 'request_date', 'resident_note', 'status', 'reviewed_by', 'reviewed_at', 'remarks',
        'cancelled_at', 'cancelled_by', 'fulfilled_at', 'reminded_at', 'prescription_verified_at', 'prescription_verified_by'];
    protected $casts = ['request_date' => 'datetime', 'reviewed_at' => 'datetime', 'cancelled_at' => 'datetime', 'fulfilled_at' => 'datetime', 'reminded_at' => 'datetime', 'prescription_verified_at' => 'datetime'];
    protected $appends = ['claim_by', 'pickup_deadline', 'pickup_days'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class, 'request_id', 'request_id');
    }

    public function prescriptionVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescription_verified_by', 'user_id');
    }

    /** Names of the requested medicines that need a doctor's prescription. */
    public function prescriptionMedicines(): \Illuminate\Support\Collection
    {
        $this->loadMissing('items.medicine');
        return $this->items->filter(fn ($i) => $i->medicine?->requires_prescription)->map(fn ($i) => $i->medicine->medicine_name)->values();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    /** Last day to claim an approved medicine request before it expires. */
    public function getClaimByAttribute(): ?string
    {
        if ($this->request_type !== 'medicine' || $this->status !== 'approved' || ! $this->reviewed_at) {
            return null;
        }
        return self::claimDeadline($this->reviewed_at)->toIso8601String();
    }

    /**
     * Claim deadline of a medicine request that was approved (ready for pickup), kept after it is
     * expired or cancelled so notifications and the tracking page can still show the pickup period.
     */
    public function getPickupDeadlineAttribute(): ?string
    {
        if ($this->request_type !== 'medicine' || ! $this->reviewed_at || in_array($this->status, ['pending', 'rejected'], true)) {
            return null;
        }
        return self::claimDeadline($this->reviewed_at)->toIso8601String();
    }

    /** Number of days the resident has to claim an approved medicine request (botika.unclaimed_days). */
    public function getPickupDaysAttribute(): ?int
    {
        return $this->pickup_deadline ? (int) config('botika.unclaimed_days') : null;
    }

    /**
     * Pickup deadline of an approved medicine request: the end of the day, botika.unclaimed_days
     * after approval (approved Oct 12 → claim on or before Oct 19). After that it is cancelled (expired).
     */
    public static function claimDeadline(\DateTimeInterface $approvedAt): \Carbon\Carbon
    {
        return \Carbon\Carbon::instance($approvedAt)->copy()->addDays(config('botika.unclaimed_days'))->endOfDay();
    }

    /**
     * Short list of the requested medicines for notification messages,
     * e.g. "Paracetamol 500mg x10, Amoxicillin 500mg x21 and 1 more".
     */
    public function itemsSummary(int $max = 2): string
    {
        $this->loadMissing('items.medicine');
        $parts = $this->items->map(fn ($i) => trim(($i->medicine?->medicine_name ?? 'Medicine') . ' x' . $i->quantity));
        $text = $parts->take($max)->implode(', ');
        $more = $parts->count() - $max;
        return $more > 0 ? "{$text} and {$more} more" : $text;
    }

    /** Date format used in all notification messages (e.g. "Oct 19, 2026"). */
    public static function noticeDate(\DateTimeInterface $date): string
    {
        return \Carbon\Carbon::instance($date)->format('M j, Y');
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
