<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One permission the administrator turned on or off for a role (see config/permissions.php). */
class RolePermission extends Model
{
    protected $primaryKey = 'role_permission_id';

    const CREATED_AT = null;

    protected $fillable = ['role', 'permission', 'allowed', 'updated_by'];
    protected $casts = ['allowed' => 'boolean', 'updated_at' => 'datetime'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }
}
