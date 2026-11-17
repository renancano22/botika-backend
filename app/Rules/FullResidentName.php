<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Completeness check for self registration; does not verify a person's legal identity. */
class FullResidentName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = preg_split('/\s+/u', trim($value));
        while ($parts && preg_match('/^(?:[Jj][Rr]|[Ss][Rr]|II|III|IV|V|VI|VII|VIII|IX|X)\.?$/', end($parts))) {
            array_pop($parts);
        }
        $letters = fn (string $part): int => preg_match_all('/\pL/u', $part);
        if (count($parts) < 2 || $letters($parts[0]) < 2 || $letters(end($parts)) < 2
            || collect($parts)->contains(fn ($part) => $letters($part) === 0)) {
            $fail('Enter your complete first and last names, not a single name or initials. Include your middle name if you have one.');
        }
    }
}
