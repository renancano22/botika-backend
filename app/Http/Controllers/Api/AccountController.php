<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PasswordAttempts;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Change own password (administrator and pharmacy staff from the menu; everyone at the first log-in with
 * a temporary password). Residents can also change it in Edit Profile.
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
            throw ValidationException::withMessages(['current_password' => 'Your temporary password has expired. Please ask the administrator to set a new one.']);
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
}
