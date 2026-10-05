<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NOTIFICATIONS entity: log of SMS messages sent to residents. */
class Notification extends Model
{
    protected $primaryKey = 'notification_id';
    public $timestamps = false;

    protected $fillable = ['resident_id', 'request_id', 'message', 'channel', 'status', 'sent_at'];
    protected $casts = ['sent_at' => 'datetime'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
