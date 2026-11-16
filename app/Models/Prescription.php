<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Photo or PDF of a doctor's prescription uploaded with a medicine request. */
class Prescription extends Model
{
    protected $primaryKey = 'prescription_id';
    public $timestamps = false;

    protected $fillable = ['request_id', 'file_name', 'mime_type', 'size', 'file_data', 'uploaded_at', 'medicine_ids'];
    protected $casts = ['uploaded_at' => 'datetime', 'size' => 'integer', 'medicine_ids' => 'array'];

    /** The file itself is only sent when someone opens it. */
    protected $hidden = ['file_data'];

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    public const MAX_BYTES = 4 * 1024 * 1024; // 4 MB
    public const MAX_FILES = 5;
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024;

    public function request(): BelongsTo
    {
        return $this->belongsTo(MedicineRequest::class, 'request_id', 'request_id');
    }
}
