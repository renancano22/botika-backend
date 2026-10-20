<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Services\PermissionService;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const ROLE_RESIDENT = 'resident';

    protected $primaryKey = 'user_id';
    protected $authPasswordName = 'password_hash';

    const UPDATED_AT = null;

    protected $fillable = ['name', 'email', 'password_hash', 'role', 'is_active'];
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'temp_password_expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getRememberTokenName()
    {
        return null; // API token auth only; the ERD has no remember_token column
    }

    /** Finds an account by email address or by a resident's registered mobile number. */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            return static::where('email', strtolower($login))->first();
        }
        return Resident::where('contact_no', Resident::normalizePhone($login))->first()?->user;
    }

    /** Features this user may use (Admin → User Permissions). Added to /me, login and profile responses. */
    public function getPermissionsAttribute(): array
    {
        return app(PermissionService::class)->granted($this);
    }

    public function hasPermission(string $permission): bool
    {
        return app(PermissionService::class)->allows($this, $permission);
    }

    /**
     * The administrator set this password (new account or reset): the user must change it at the next
     * log-in, and it stops working after botika.temp_password_days. Other devices are logged out.
     * (Not mass assignable on purpose: only these methods change it.)
     */
    public function setTemporaryPassword(string $password): void
    {
        $this->password_hash = $password; // hashed by the cast
        $this->must_change_password = true;
        $this->temp_password_expires_at = now()->addDays(config('botika.temp_password_days'));
    }

    /** The user chose their own password. */
    public function setOwnPassword(string $password): void
    {
        $this->password_hash = $password;
        $this->must_change_password = false;
        $this->temp_password_expires_at = null;
    }

    /** After a password change: log out every other phone/computer; the current one stays logged in. */
    public function logoutOtherDevices(): void
    {
        $current = $this->currentAccessToken();
        $this->tokens()->when($current?->id, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
    }

    public function temporaryPasswordExpired(): bool
    {
        return $this->must_change_password && $this->temp_password_expires_at && $this->temp_password_expires_at->isPast();
    }

    public function resident(): HasOne
    {
        return $this->hasOne(Resident::class, 'user_id', 'user_id');
    }
}
