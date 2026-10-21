<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PasswordAttempts;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Own account: change password (required at the first log-in with a temporary password) and, for the
 * administrator and pharmacy staff, My Profile. Residents use ProfileController (Edit Profile).
 */
class AccountController extends Controller
{
    public function changePassword(Request $request)
    {
        $user = $request->user();
        $temporary = $user->must_change_password;

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [...Rules::password(true, [$user->name, $user->email, $user->resident?->contact_no]), 'confirmed', 'different:current_password'],
        ], Rules::messages() + [
            'current_password.required' => $temporary ? 'Enter the temporary password given by the administrator.' : 'Enter your current password.',
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
            'password.different' => $temporary ? 'Your new password must be different from the temporary password.' : 'Your new password must be different from your current password.',
        ]);

        if ($user->temporaryPasswordExpired()) {
            throw ValidationException::withMessages(['current_password' => 'Your temporary password has expired. Please ask the administrator to reset it.']);
        }
        PasswordAttempts::verify($user, $data['current_password'], 'current_password',
            $temporary ? 'The temporary password is incorrect.' : 'Your current password is incorrect.');

        $user->setOwnPassword($data['password']);
        $user->save();
        $user->logoutOtherDevices();

        return response()->json([
            'message' => 'Your password has been changed.',
            'user' => AuthController::withPhoto($user->fresh()),
        ]);
    }

    /**
     * My Profile (administrator and pharmacy staff) → Edit Profile → Save Changes. In one request:
     *  - full name (the email is the username: only the administrator changes it, in User Accounts);
     *  - password: optional, only when one of the password fields is filled. Same rules as Change password:
     *    the current password must be correct (wrong tries are limited), the new one follows the password
     *    policy, and other devices are logged out.
     *  - profile picture: optional new picture (photo) or remove_photo, the same as the resident's Edit Profile.
     * Nothing is saved when any check fails.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $request->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name')))]);
        $changePassword = $request->filled('current_password') || $request->filled('password') || $request->filled('password_confirmation');

        $rules = ['name' => Rules::name(), 'email' => 'prohibited', 'role' => 'prohibited',
            'photo' => Rules::photo(), 'remove_photo' => ['sometimes', 'boolean']];
        if ($changePassword) {
            $rules['current_password'] = ['required', 'string'];
            $rules['password'] = [...Rules::password(true, [$request->input('name'), $user->name, $user->email]), 'confirmed', 'different:current_password'];
        }
        $data = $request->validate($rules, Rules::messages() + Rules::PHOTO_MESSAGES + [
            'email.prohibited' => 'Your email address is your username. Please ask the administrator to change it.',
            'current_password.required' => 'Enter your current password to change your password.',
            'password.required' => 'Enter a new password, or close Change Password to keep your current password.',
            'password.confirmed' => 'The new password and its confirmation do not match.',
            'password.different' => 'The new password must be different from your current password.',
        ]);

        if ($changePassword) {
            PasswordAttempts::verify($user, $data['current_password'], 'current_password', 'Your current password is incorrect. Your changes were not saved.');
        }

        DB::transaction(function () use ($user, $data, $changePassword, $request) {
            $user->name = $data['name'];
            if (! empty($data['photo'])) {
                $user->photo = $data['photo'];
            } elseif ($request->boolean('remove_photo')) {
                $user->photo = null;
            }
            if ($changePassword) {
                $user->setOwnPassword($data['password']);
            }
            $user->save();
        });
        if ($changePassword) {
            $user->logoutOtherDevices();
        }

        return [
            'user' => AuthController::withPhoto($user->fresh()),
            'password_changed' => $changePassword,
        ];
    }

    /** My Profile → Change Profile Picture (administrator and pharmacy staff). */
    public function updatePhoto(Request $request)
    {
        $data = $request->validate(['photo' => Rules::photo(true)], Rules::PHOTO_MESSAGES);
        $user = $request->user();
        $user->photo = $data['photo'];
        $user->save();

        return ['user' => AuthController::withPhoto($user->fresh())];
    }

    /** My Profile → Delete Profile Picture: the first letter of the name is shown instead. */
    public function deletePhoto(Request $request)
    {
        $user = $request->user();
        $user->photo = null;
        $user->save();

        return ['user' => AuthController::withPhoto($user->fresh())];
    }
}
