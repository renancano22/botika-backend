<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\User;
use App\Support\LoginAttempts;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** User Management (DFD Process 1.0): resident registration, login and logout. */
class AuthController extends Controller
{
    /** Residents register with a mobile number; an email address is optional. */
    public const ALREADY_REGISTERED = 'A resident with this name is already registered in this barangay. If this is you, log in with your old mobile number or email, '
        . 'or use "Forgot password?". If you changed your number, update it in My Profile after logging in, or ask the Botika ng Bayan staff for help. '
        . 'If you are a different person with the same name, add your middle name or suffix (e.g. Jr., Sr.).';

    public function register(Request $request)
    {
        $request->merge([
            'contact_no' => Resident::normalizePhone($request->input('contact_no')),
            'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null,
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'name' => Rules::name(),
            'email' => [...Rules::gmail(false), 'unique:users,email'],
            'password' => [...Rules::password(true, [$request->input('name'), $request->input('email'), $request->input('contact_no')]), 'confirmed'],
            'barangay' => Rules::barangay(),
            'address_line' => ['nullable', 'string', 'max:120'],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:residents,contact_no'],
        ], Rules::messages() + [
            'contact_no.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
            'contact_no.unique' => 'This mobile number is already registered. Please log in instead.',
            'email.unique' => 'This email is already registered. Please log in instead.',
        ]);

        // Already registered? The same name in the same barangay is refused, as in registration by the staff, so one
        // resident does not get a second account and Resident ID (split history, request limits per account).
        [$same] = Resident::possibleDuplicates($data['name'], $data['barangay']);
        if ($same->isNotEmpty()) {
            throw ValidationException::withMessages(['name' => self::ALREADY_REGISTERED]);
        }

        // Stored as e.g. "Purok 3, Zone I Poblacion, Bulan, Sorsogon".
        $data['address'] = Resident::composeAddress($data['address_line'] ?? null, $data['barangay']);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'password_hash' => $data['password'],
                'role' => User::ROLE_RESIDENT,
            ]);

            $resident = Resident::create([
                'user_id' => $user->user_id,
                'name' => $data['name'],
                'address' => $data['address'],
                'contact_no' => $data['contact_no'],
                'qr_code' => 'TEMP-' . uniqid(),
            ]);
            $resident->update(['qr_code' => Resident::makePatientId($resident->resident_id)]);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => self::withPhoto($user),
        ], 201);
    }

    /** Log in with an email address OR a resident's registered mobile number. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ], [
            'login.required' => 'Enter your email or mobile number.',
        ]);

        $user = User::findByLogin($data['login']);

        // Repeated wrong log-in attempts within a short time are temporarily blocked (protects against guessing).
        LoginAttempts::ensureNotBlocked($request, $data['login'], $user);

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            $left = LoginAttempts::failed($request, $data['login'], $user); // blocks (429) on the last allowed try
            throw ValidationException::withMessages(['login' => 'Invalid email/mobile number or password.'
                . ($left <= 2 ? " {$left} more " . ($left === 1 ? 'attempt' : 'attempts') . ' before logging in is temporarily blocked.' : '')]);
        }
        LoginAttempts::clear($request, $data['login'], $user);
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This account has been deactivated. Please contact the administrator.']);
        }
        if ($user->temporaryPasswordExpired()) {
            throw ValidationException::withMessages(['login' => 'Your temporary password has expired. Please ask the administrator to reset it.']);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => self::withPhoto($user),
        ]);
    }

    public function me(Request $request)
    {
        return self::withPhoto($request->user());
    }

    /**
     * The logged-in user, including their profile picture (shown in the top bar: residents.photo for a
     * resident, users.photo for the administrator and pharmacy staff) and the features they may use
     * (User Permissions), so the menu and pages match what the server allows.
     */
    public static function withPhoto(User $user): User
    {
        $user->load('resident');
        $user->resident?->makeVisible('photo');
        if ($user->role !== User::ROLE_RESIDENT) {
            $user->makeVisible('photo');
        }
        $user->append('permissions');
        return $user;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }
}
