<?php

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;

/**
 * User Permissions and Restrictions (Admin → User Permissions).
 * The administrator always has every permission; Pharmacy Staff and Residents have the permissions
 * the administrator allowed (or the defaults in config/permissions.php).
 */
class PermissionService
{
    /** @var array<string, array<string, bool>> role => [permission => allowed], loaded once per request */
    private array $cache = [];

    /** Permissions that can be managed for a role: key => [module, label, description, default]. */
    public function catalog(string $role): array
    {
        return config("permissions.permissions.{$role}", []);
    }

    /** @return array<string, bool> every permission of the role with its current setting */
    public function forRole(string $role): array
    {
        if (isset($this->cache[$role])) {
            return $this->cache[$role];
        }
        $saved = RolePermission::where('role', $role)->pluck('allowed', 'permission');
        $result = [];
        foreach ($this->catalog($role) as $key => $def) {
            $result[$key] = (bool) ($saved[$key] ?? $def[3]);
        }
        return $this->cache[$role] = $result;
    }

    /** @return string[] permission keys the user may use (the administrator: all of them) */
    public function granted(User $user): array
    {
        if ($user->role === User::ROLE_ADMIN) {
            return collect(config('permissions.permissions'))->flatMap(fn ($list) => array_keys($list))->unique()->values()->all();
        }
        return array_keys(array_filter($this->forRole($user->role)));
    }

    public function allows(User $user, string $permission): bool
    {
        if ($user->role === User::ROLE_ADMIN) {
            return true;
        }
        return $this->forRole($user->role)[$permission] ?? false;
    }

    /** Saves the administrator's choices for one role. */
    public function update(string $role, array $permissions, User $admin): void
    {
        foreach ($this->catalog($role) as $key => $def) {
            if (! array_key_exists($key, $permissions)) continue;
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission' => $key],
                ['allowed' => (bool) $permissions[$key], 'updated_by' => $admin->user_id],
            );
        }
        unset($this->cache[$role]);
    }

    /** Back to the default access for a role. */
    public function reset(string $role): void
    {
        RolePermission::where('role', $role)->delete();
        unset($this->cache[$role]);
    }

    /** Plain-language name of a feature, e.g. "Approve and reject medicine requests". */
    public function label(string $role, string $permission): ?string
    {
        return $this->catalog($role)[$permission][1] ?? null;
    }
}
