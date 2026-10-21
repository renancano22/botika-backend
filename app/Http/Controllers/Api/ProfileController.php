<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Support\PasswordAttempts;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Resident "My Profile": view and edit their own information, profile picture and password.
 * The QR code / Resident ID is shown here too (it never changes).
 */
class ProfileController extends Controller
{
    /** Profile pictures: the same rules for every role (Rules::photo). */
    private const PHOTO_MESSAGES = Rules::PHOTO_MESSAGES;

    public function show(Request $request)
    {
        return $this->profile($request);
    }

    /**
     * Edit Profile "Save Changes" (sent only after the resident confirms). In one request:
     *  - personal information (always);
     *  - password: optional. Only when one of the password fields is filled; the current password must
     *    be correct. When all of them are blank the password is never touched;
     *  - profile picture: optional new picture (photo) or remove_photo.
     * Everything is checked first and saved together, so nothing is saved when any check fails.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $resident = $user->resident;

        $request->merge([
            'contact_no' => Resident::normalizePhone($request->input('contact_no')),
            'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null,
            'name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name'))),
            'address_line' => preg_replace('/\s+/u', ' ', trim(strip_tags((string) $request->input('address_line', '')))),
        ]);
        $changePassword = $request->filled('current_password') || $request->filled('password') || $request->filled('password_confirmation');

        $rules = [
            'name' => Rules::name(),
            'email' => [...Rules::gmail(false), Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/',
                Rule::unique('residents', 'contact_no')->ignore($resident->resident_id, 'resident_id')],
            'barangay' => Rules::barangay(),
            'address_line' => ['nullable', 'string', 'max:120'],
            'photo' => Rules::photo(),
            'remove_photo' => ['sometimes', 'boolean'],
        ];
        if ($changePassword) {
            $rules['current_password'] = ['required', 'string'];
            $rules['password'] = [...Rules::password(true, [$request->input('name'), $request->input('email'), $request->input('contact_no'), $user->name, $user->email]),
                'confirmed', 'different:current_password'];
        }

        $data = $request->validate($rules, Rules::messages() + self::PHOTO_MESSAGES + [
            'contact_no.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
            'contact_no.unique' => 'This mobile number is already used by another account.',
            'email.unique' => 'This email is already used by another account.',
            'current_password.required' => 'Enter your current password to change your password.',
            'password.required' => 'Enter a new password, or leave all password fields blank to keep your current password.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
            'password.different' => 'The new password must be different from your current password.',
        ]);

        if ($changePassword) {
            PasswordAttempts::verify($user, $data['current_password'], 'current_password', 'Your current password is incorrect. Your changes were not saved.');
        }

        DB::transaction(function () use ($user, $resident, $data, $changePassword, $request) {
            $user->fill(['name' => $data['name'], 'email' => $data['email'] ?? null]);
            if ($changePassword) {
                $user->setOwnPassword($data['password']);
            }
            $user->save();

            $changes = [
                'name' => $data['name'],
                'contact_no' => $data['contact_no'],
                'address' => Resident::composeAddress($data['address_line'] ?? null, $data['barangay']),
            ];
            if (! empty($data['photo'])) {
                $changes['photo'] = $data['photo'];
            } elseif ($request->boolean('remove_photo')) {
                $changes['photo'] = null;
            }
            $resident->update($changes);
        });

        if ($changePassword) {
            $user->logoutOtherDevices(); // the old password must not keep other devices logged in
        }

        return $this->profile($request) + ['password_changed' => $changePassword];
    }

    public function updatePhoto(Request $request)
    {
        $data = $request->validate([
            'photo' => Rules::photo(true),
        ], self::PHOTO_MESSAGES);

        $request->user()->resident->update(['photo' => $data['photo']]);

        return $this->profile($request);
    }

    public function deletePhoto(Request $request)
    {
        $request->user()->resident->update(['photo' => null]);

        return $this->profile($request);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [...Rules::password(true, [$user->name, $user->email, $user->resident?->contact_no]), 'confirmed', 'different:current_password'],
        ], Rules::messages() + ['password.different' => 'The new password must be different from your current password.']);

        PasswordAttempts::verify($user, $data['current_password']);

        $user->setOwnPassword($data['password']);
        $user->save();
        $user->logoutOtherDevices();

        return response()->json(['message' => 'Your password has been changed.']);
    }

    private function profile(Request $request): array
    {
        $user = AuthController::withPhoto($request->user()->fresh());

        return [
            'user' => $user,
            ...Resident::splitAddress($user->resident->address),
        ];
    }
}
