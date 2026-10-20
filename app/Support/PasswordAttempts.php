<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks a logged-in user's current (or temporary) password before it is changed.
 * Only wrong guesses are counted: after MAX_FAILURES wrong passwords the form is locked for
 * DECAY_SECONDS, so a stolen session cannot be used to guess the password (OWASP ASVS V2.2.1).
 */
class PasswordAttempts
{
    private const MAX_FAILURES = 5;
    private const DECAY_SECONDS = 900; // 15 minutes

    /** @throws ValidationException when the password is wrong or there were too many wrong tries */
    public static function verify(User $user, string $password, string $field = 'current_password', ?string $wrongMessage = null): void
    {
        $key = 'password-check|' . $user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([$field => "Too many wrong passwords. Please wait {$minutes} minute" . ($minutes === 1 ? '' : 's') . ' and try again.']);
        }
        if (! Hash::check($password, $user->password_hash)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages([$field => $wrongMessage ?? 'Your current password is incorrect.']);
        }
        RateLimiter::clear($key);
    }
}
