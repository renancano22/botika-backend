<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account still using the temporary default password given by the administrator can only
 * read its own details, change the password and log out, until the password is changed.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['api/me', 'api/logout', 'api/account/password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'message' => 'Please change your temporary password before using BulanBotikaCare.',
                'code' => 'password_change_required',
            ], 403);
        }
        return $next($request);
    }
}
