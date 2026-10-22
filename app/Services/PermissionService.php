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

    /** [feature => the feature it needs] for a role (config permissions.requires). */
    public function requires(string $role): array
    {
        return config("permissions.requires.{$role}", []);
    }

    /**
     * @return array<string, bool> every permission of the role with its current setting. A feature whose
     * required feature is off counts as off, so a combination saved earlier can never open a back door.
     */
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
        foreach ($this->requires($role) as $key => $needed) {
            if (isset($result[$key]) && ! ($result[$needed] ?? false)) {
                $result[$key] = false;
            }
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

    /**
     * Saves the administrator's choices for one role, keeping related features consistent:
     * turning a feature on also turns on the one it needs; turning a needed feature off also turns off
     * the features that depend on it.
     */
    public function update(string $role, array $permissions, User $admin): void
    {
        $catalog = $this->catalog($role);
        $final = $this->forRole($role);
        foreach ($permissions as $key => $allowed) {
            if (isset($catalog[$key])) $final[$key] = (bool) $allowed;
        }
        foreach ($this->requires($role) as $key => $needed) {
            $neededOff = array_key_exists($needed, $permissions) && ! $permissions[$needed];
            if (! empty($permissions[$key]) && ! $neededOff) {
                $final[$needed] = true;            // turned on → what it needs is turned on too
            }
            if (! $final[$needed]) {
                $final[$key] = false;              // what it needs is off → it is off too
            }
        }
        foreach ($final as $key => $allowed) {
            $saved = RolePermission::where('role', $role)->where('permission', $key)->first();
            if ($saved ? (bool) $saved->allowed === $allowed : $allowed === (bool) $catalog[$key][3]) continue; // unchanged
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission' => $key],
                ['allowed' => $allowed, 'updated_by' => $admin->user_id],
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
