<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * User Permissions and Restrictions. Usage in routes: ->middleware('permission:inventory.manage')
 *  - The "role" middleware still decides which roles can ever use a route; this narrows it down to
 *    the features the administrator allowed for that role.
 *  - Several keys separated by "|" mean "any of these" (a route used by more than one page or role).
 *    Only the keys that exist for the user's role are checked; a route none of them applies to is allowed.
 *  - The administrator is never restricted.
 */
class EnsurePermission
{
    public function __construct(private PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $keys): Response
    {
        $user = $request->user();
        if (! $user || $user->role === User::ROLE_ADMIN) {
            return $next($request);
        }

        $catalog = $this->permissions->catalog($user->role);
        $managed = array_values(array_filter(explode('|', $keys), fn ($k) => isset($catalog[$k])));
        if (! $managed) {
            return $next($request);
        }
        foreach ($managed as $key) {
            if ($this->permissions->allows($user, $key)) {
                return $next($request);
            }
        }

        $feature = $this->permissions->label($user->role, $managed[0]);
        return response()->json([
            'message' => "Access denied. You do not have permission to use \"{$feature}\". Please contact the administrator if you need access.",
            'permission' => $managed[0],
        ], 403);
    }
}
