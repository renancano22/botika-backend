<?php

namespace App\Support;

use App\Rules\NotGuessablePassword;
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

    /**
     * The password policy used everywhere a password is chosen: at least 8 characters with a letter and a
     * number, not a common password, and without the person's own details ($personal: name, email, mobile).
     */
    public static function password(bool $required = true, array $personal = []): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:100', Password::min(8)->letters()->numbers(), new NotGuessablePassword($personal)];
    }

    /**
     * Profile picture (residents, administrator and pharmacy staff): resized in the browser to a small
     * JPEG/PNG/WebP and sent as a data URL; at most about 300 KB.
     */
    public static function photo(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:400000', 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'];
    }

    public const PHOTO_MESSAGES = [
        'photo.required' => 'Choose a picture.',
        'photo.max' => 'The picture is too large. Please choose a smaller one.',
        'photo.regex' => 'Please choose a JPG, PNG or WebP picture.',
    ];

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
