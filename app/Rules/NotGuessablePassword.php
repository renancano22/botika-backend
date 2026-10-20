<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses passwords that are easy to guess (NIST SP 800-63B §5.1.1.2, OWASP ASVS V2.1.7):
 *  - very common passwords (a short local list, so it works without internet access);
 *  - passwords containing the person's own details or the system's name ("context-specific words").
 * Length and letters+numbers are checked separately by Rules::password().
 */
class NotGuessablePassword implements ValidationRule
{
    /** Common passwords that still pass "8 characters with letters and numbers". Compared case-insensitively. */
    private const COMMON = [
        'password1', 'password12', 'password123', 'password1234', 'passw0rd', 'pass1234', 'pass12345',
        'qwerty12', 'qwerty123', 'qwerty1234', 'qwe12345', 'abc12345', 'abcd1234', 'abc123456', 'a1b2c3d4',
        '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'asdf1234', 'test1234', 'admin123', 'admin1234',
        'welcome1', 'welcome123', 'letmein1', 'iloveyou1', 'iloveyou2', 'iloveyou143', 'mahalkita1', 'iloveu143',
        'changeme1', 'default1', 'temp1234', 'trustno1', 'sunshine1', 'princess1', 'football1', 'baseball1',
        'monkey123', 'dragon123', 'master123', 'superman1', 'batman123', 'michael1', 'charlie1',
        'pilipinas1', 'philippines1', 'user1234', 'login123', 'secret12',
    ];

    /** Words about this system that are easy to guess here. */
    private const SYSTEM_WORDS = ['botika', 'bulan', 'sorsogon', 'bayan', 'pharmacy'];

    /** @param array<int, string|null> $personal the user's name, email and similar details */
    public function __construct(private array $personal = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') return;
        $password = mb_strtolower($value);

        if (in_array($password, self::COMMON, true)) {
            $fail('This password is too common and easy to guess. Please choose a different one.');
            return;
        }
        foreach ([...self::SYSTEM_WORDS, ...$this->personalWords()] as $word) {
            if (str_contains($password, $word)) {
                $fail('Your password must not contain your name, email address, mobile number or the name of the Botika.');
                return;
            }
        }
    }

    /** Name parts, the part of the email before "@", and the mobile number (only parts of 4+ characters). */
    private function personalWords(): array
    {
        $words = [];
        foreach ($this->personal as $detail) {
            $detail = mb_strtolower(trim((string) $detail));
            if ($detail === '') continue;
            if (str_contains($detail, '@')) {
                $words[] = strstr($detail, '@', true);
                continue;
            }
            if (preg_match('/^\+?\d[\d\s-]*$/', $detail)) {
                $words[] = preg_replace('/\D/', '', $detail);
                continue;
            }
            array_push($words, ...preg_split("/[\\s.'\\-]+/u", $detail));
        }
        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 4)));
    }
}
