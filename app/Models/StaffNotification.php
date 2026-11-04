<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A notification for an administrator or pharmacy staff account (in the app only). */
class StaffNotification extends Model
{
    protected $primaryKey = 'staff_notification_id';
    public $timestamps = false;

    protected $fillable = ['user_id', 'type', 'title', 'message', 'request_id', 'link', 'read_at', 'created_at'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];
}
