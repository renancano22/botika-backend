<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only application audit trail. No edit or delete API is provided. */
class ResidentCorrection extends Model
{
    protected $primaryKey = 'correction_id';
    public $timestamps = false;
    protected $guarded = ['correction_id'];
    protected $casts = ['changes' => 'array', 'verified_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Correction history cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Correction history cannot be deleted.'));
    }
}
