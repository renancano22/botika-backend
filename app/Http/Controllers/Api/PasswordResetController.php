<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsService;
use App\Support\Rules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Forgot password?" for every account (admin, staff and residents).
 *  1. The user enters their email or mobile number and receives a 6-digit code
 *     (by SMS to a resident's mobile number, or by email).
 *  2. They enter the code and a new password. Codes expire after 10 minutes and
 *     allow 5 wrong tries.
 */
class PasswordResetController extends Controller
{
    private const EXPIRES_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private SmsService $sms) {}

    public function sendCode(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:255'], [
            'login.required' => 'Enter your email or mobile number.',
        ]);

        $user = User::findByLogin($data['login']);

        if ($user && $user->is_active) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_codes')->where('user_id', $user->user_id)->delete();
            DB::table('password_reset_codes')->insert([
                'user_id' => $user->user_id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
                'created_at' => now(),
            ]);

            $this->deliver($user, $data['login'], $code);
        }

        // Same answer whether or not the account exists, so nobody can check which numbers/emails are registered.
        return response()->json([
            'message' => 'If an account matches, a 6-digit code was sent to its registered mobile number or email. The code expires in ' . self::EXPIRES_MINUTES . ' minutes.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:255',
            'code' => 'required|digits:6',
            'password' => [...Rules::password(), 'confirmed'],
        ], [
            'code.digits' => 'The code has 6 digits.',
        ]);

        $invalid = ValidationException::withMessages(['code' => 'This code is invalid or has expired. Please request a new one.']);

        $user = User::findByLogin($data['login']);
        $row = $user ? DB::table('password_reset_codes')->where('user_id', $user->user_id)->first() : null;

        if (! $user || ! $user->is_active || ! $row
            || Carbon::parse($row->expires_at)->isPast()
            || $row->attempts >= self::MAX_ATTEMPTS) {
            throw $invalid;
        }

        if (! Hash::check($data['code'], $row->code_hash)) {
            DB::table('password_reset_codes')->where('id', $row->id)->increment('attempts');
            $left = self::MAX_ATTEMPTS - $row->attempts - 1;
            throw ValidationException::withMessages([
                'code' => $left > 0 ? "Incorrect code. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.' : $invalid->getMessage(),
            ]);
        }

        $user->password_hash = $data['password'];
        $user->save();
        DB::table('password_reset_codes')->where('user_id', $user->user_id)->delete();
        $user->tokens()->delete(); // log out every device that used the old password

        return response()->json(['message' => 'Your password has been changed. You can now log in.']);
    }

    /** Residents get the code by SMS (unless they typed their email); staff and admin get it by email. */
    private function deliver(User $user, string $login, string $code): void
    {
        $message = "BulanBotikaCare: Your password reset code is {$code}. It expires in " . self::EXPIRES_MINUTES . ' minutes. Do not share this code with anyone.';
        $mobile = $user->resident?->contact_no;
        $useEmail = $user->email && (str_contains($login, '@') || ! $mobile);

        if (! $useEmail && $mobile) {
            $this->sms->sendTo($mobile, $message);
            return;
        }

        try {
            Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject('BulanBotikaCare password reset code'));
        } catch (Throwable $e) {
            Log::warning('Password reset email failed: ' . $e->getMessage());
        }
    }
}
