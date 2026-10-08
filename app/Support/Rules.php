<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Validation rules shared by registration, user accounts and password reset. */
class Rules
{
    public const GMAIL_REGEX = '/^[A-Za-z0-9._%+\-]+@gmail\.com$/i';

    /** Letters (including ñ), spaces, periods, hyphens and apostrophes, e.g. "Ma. Cruz-De la Peña". */
    public static function name(): array
    {
        return ['required', 'string', 'min:2', 'max:100', "regex:/^[\\pL\\s.'\\-]+$/u"];
    }

    /** A Gmail address (…@gmail.com). */
    public static function gmail(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'email', 'max:255', 'regex:' . self::GMAIL_REGEX];
    }

    /** At least 8 characters with at least one letter and one number. */
    public static function password(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:100', Password::min(8)->letters()->numbers()];
    }

    public static function barangay(): array
    {
        return ['required', 'string', Rule::in(config('botika.barangays'))];
    }

    public static function messages(): array
    {
        return [
            'name.regex' => 'Name can only contain letters, spaces, periods (.), hyphens (-) and apostrophes (\').',
            'email.regex' => 'Please use a Gmail address (ending in @gmail.com).',
            'barangay.in' => 'Please choose a barangay of Bulan from the list.',
        ];
    }
}
