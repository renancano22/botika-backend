<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Log-in protection: repeated wrong log-in attempts within a short time are temporarily blocked, so passwords
 * cannot be guessed (OWASP ASVS V2.2.1). Only wrong passwords are counted, and a successful log-in resets the count.
 *  - one account from one device/network: MAX_FAILURES wrong passwords in BLOCK_SECONDS blocks it for BLOCK_SECONDS;
 *  - one device/network trying many accounts: MAX_IP_FAILURES wrong passwords in BLOCK_SECONDS blocks it too.
 * The count is per account AND network, so someone guessing elsewhere cannot lock the real user out.
 * The same message is used whether or not the account exists (no hint about which accounts are registered).
 */
class LoginAttempts
{
    public const MAX_FAILURES = 5;
    public const MAX_IP_FAILURES = 20;
    public const BLOCK_SECONDS = 900; // 15 minutes

    /** Same account whatever form was typed (email in any case, 0917… / 63917… / +63917…). */
    private static function accountKey(Request $request, string $login, ?User $user): string
    {
        $id = $user ? 'user:' . $user->getKey()
            : (str_contains($login, '@') ? strtolower(trim($login)) : (Resident::normalizePhone($login) ?: strtolower(trim($login))));
        return 'login|' . sha1($id) . '|' . $request->ip();
    }

    private static function ipKey(Request $request): string
    {
        return 'login-ip|' . $request->ip();
    }

    /** @throws HttpResponseException (429) while log-in is blocked */
    public static function ensureNotBlocked(Request $request, string $login, ?User $user): void
    {
        foreach ([[self::accountKey($request, $login, $user), self::MAX_FAILURES], [self::ipKey($request), self::MAX_IP_FAILURES]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                self::blocked(RateLimiter::availableIn($key));
            }
        }
    }

    /** Counts a wrong password; returns how many tries are left before log-in is blocked (0 = blocked now). */
    public static function failed(Request $request, string $login, ?User $user): int
    {
        $key = self::accountKey($request, $login, $user);
        RateLimiter::hit($key, self::BLOCK_SECONDS);
        RateLimiter::hit(self::ipKey($request), self::BLOCK_SECONDS);
        self::ensureNotBlocked($request, $login, $user); // that was the last try: blocked until the 15 minutes from the first wrong try are over
        return min(RateLimiter::remaining($key, self::MAX_FAILURES), RateLimiter::remaining(self::ipKey($request), self::MAX_IP_FAILURES));
    }

    /** Correct password: the count for this account starts over. */
    public static function clear(Request $request, string $login, ?User $user): void
    {
        RateLimiter::clear(self::accountKey($request, $login, $user));
    }

    /** @throws HttpResponseException */
    public static function blocked(int $seconds): never
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $message = 'Too many failed log-in attempts. For your security, logging in is temporarily blocked. '
            . "Please try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . ', or use "Forgot password?" to reset your password.';
        throw new HttpResponseException(response()->json(['message' => $message, 'errors' => ['login' => [$message]], 'retry_after' => $seconds], 429)
            ->header('Retry-After', (string) $seconds));
    }
}
