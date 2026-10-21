<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Temporary passwords for accounts created or reset by the administrator. They are made here, on the
 * server, with a cryptographically secure random generator (random_int), so the administrator never
 * chooses one. The plain password is returned once in the API response and only its hash is stored.
 */
class TemporaryPassword
{
    // No look-alike characters (0/O, 1/l/I), so it can be read out or copied by hand.
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    private const DIGITS = '23456789';

    /**
     * 12 characters (8 letters, 4 numbers, mixed) that pass the password policy for this person and, for an
     * existing account ($user), are not its current or a previous password (password history).
     */
    public static function generate(array $personal = [], ?User $user = null): string
    {
        for ($try = 0; $try < 20; $try++) {
            $chars = [];
            for ($i = 0; $i < 8; $i++) $chars[] = self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
            for ($i = 0; $i < 4; $i++) $chars[] = self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
            for ($i = count($chars) - 1; $i > 0; $i--) { // Fisher–Yates shuffle with a secure random number
                $j = random_int(0, $i);
                [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
            }
            $password = implode('', $chars);
            if (Validator::make(['password' => $password], ['password' => Rules::password(true, $personal)])->passes()
                && ! $user?->usedPasswordBefore($password)) {
                return $password;
            }
        }
        throw new RuntimeException('Could not generate a temporary password.');
    }
}
