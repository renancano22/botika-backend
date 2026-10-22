<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin → User Permissions: what Pharmacy Staff and Residents are allowed to use. */
class PermissionController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    /** Every role with its permissions grouped by module, plus the features only the administrator can use. */
    public function index()
    {
        $roles = [];
        foreach (config('permissions.roles') as $role => $label) {
            $current = $this->permissions->forRole($role);
            $last = RolePermission::with('updater:user_id,name')->where('role', $role)->latest('updated_at')->first();
            $roles[] = [
                'role' => $role,
                'label' => $label,
                'permissions' => collect($this->permissions->catalog($role))->map(fn ($def, $key) => [
                    'key' => $key,
                    'module' => $def[0],
                    'label' => $def[1],
                    'description' => $def[2],
                    'default' => (bool) $def[3],
                    'allowed' => $current[$key],
                    'requires' => $this->permissions->requires($role)[$key] ?? null, // the feature it needs (null = none)
                ])->values(),
                'updated_at' => $last?->updated_at,
                'updated_by' => $last?->updater?->name,
            ];
        }

        return [
            'roles' => $roles,
            'admin_only' => collect(config('permissions.admin_only'))->map(fn ($p) => ['module' => $p[0], 'label' => $p[1]]),
        ];
    }

    public function update(Request $request, string $role)
    {
        $keys = array_keys($this->permissions->catalog($role));
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'boolean',
        ], [
            'permissions.required' => 'Choose the permissions to save.',
        ]);
        $unknown = array_diff(array_keys($data['permissions']), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission: ' . implode(', ', $unknown) . '.']);
        }

        $this->permissions->update($role, $data['permissions'], $request->user());
        return $this->index();
    }

    public function reset(string $role)
    {
        $this->permissions->reset($role);
        return $this->index();
    }
}
