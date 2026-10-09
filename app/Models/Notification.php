<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOTIFICATIONS entity: log of SMS messages sent to residents (request updates and the
 * administrator's announcements). Residents also see them in the app; read_at = null means unread.
 */
class Notification extends Model
{
    protected $primaryKey = 'notification_id';
    public $timestamps = false;

    protected $fillable = ['resident_id', 'request_id', 'type', 'message', 'channel', 'status', 'sent_at', 'read_at'];
    protected $casts = ['sent_at' => 'datetime', 'read_at' => 'datetime'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', 'resident_id');
    }
}
