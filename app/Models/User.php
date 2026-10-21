<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

    public const PASSWORD_REUSED = 'You cannot reuse a previously used password. Please choose a different password.';

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

    protected static function booted(): void
    {
        // Password history: whenever the password changes, the hash of the old one is kept (never the plain
        // password), whoever changed it (the user, Forgot password, or the administrator's Reset password).
        static::updated(function (User $user) {
            $old = $user->getRawOriginal('password_hash');
            if ($old && $user->wasChanged('password_hash')) {
                $user->rememberPreviousPassword($old);
            }
        });
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
        $this->ensurePasswordNotReused($password); // TemporaryPassword::generate() already skips used ones
        $this->password_hash = $password; // hashed by the cast
        $this->must_change_password = true;
        $this->temp_password_expires_at = now()->addDays(config('botika.temp_password_days'));
    }

    /**
     * The user chose their own password (change password, Edit Profile, Forgot password).
     * Call it only after the current password or the reset code was checked: the "already used" answer
     * must never help someone guess a password.
     * @throws ValidationException when the password was used before
     */
    public function setOwnPassword(string $password): void
    {
        $this->ensurePasswordNotReused($password);
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

    /** Number of passwords that cannot be reused, counting the current one (at least the current one). */
    public static function passwordHistorySize(): int
    {
        return max(1, (int) config('botika.password_history', 5));
    }

    /** True when $password is the current password or one of the previous ones kept in the history. */
    public function usedPasswordBefore(string $password): bool
    {
        if ($this->exists && $this->getRawOriginal('password_hash') && Hash::check($password, $this->getRawOriginal('password_hash'))) {
            return true;
        }
        return $this->previousPasswordHashes()->contains(fn (string $hash) => Hash::check($password, $hash));
    }

    /** @throws ValidationException (on the "password" field) when the password was used before */
    public function ensurePasswordNotReused(string $password): void
    {
        if ($this->usedPasswordBefore($password)) {
            throw ValidationException::withMessages(['password' => self::PASSWORD_REUSED]);
        }
    }

    /** Hashes of the previous passwords that still cannot be reused (newest first). Never sent to the browser. */
    private function previousPasswordHashes(): \Illuminate\Support\Collection
    {
        $keep = self::passwordHistorySize() - 1; // the current password is the other one
        if (! $this->exists || $keep < 1) {
            return collect();
        }
        return DB::table('password_histories')->where('user_id', $this->user_id)
            ->orderByDesc('id')->limit($keep)->pluck('password_hash');
    }

    /** Adds the old password's hash to the history and removes the ones that are no longer needed. */
    private function rememberPreviousPassword(string $hash): void
    {
        $keep = self::passwordHistorySize() - 1;
        if ($keep > 0) {
            DB::table('password_histories')->insert(['user_id' => $this->user_id, 'password_hash' => $hash, 'created_at' => now()]);
        }
        $kept = DB::table('password_histories')->where('user_id', $this->user_id)->orderByDesc('id')->limit($keep)->pluck('id');
        DB::table('password_histories')->where('user_id', $this->user_id)->whereNotIn('id', $kept)->delete();
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
