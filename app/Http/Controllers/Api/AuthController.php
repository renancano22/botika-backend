<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\User;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** User Management (DFD Process 1.0): resident registration, login and logout. */
class AuthController extends Controller
{
    /** Residents register with a mobile number; an email address is optional. */
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
            'password' => [...Rules::password(), 'confirmed'],
            'barangay' => Rules::barangay(),
            'address_line' => ['nullable', 'string', 'max:120'],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:residents,contact_no'],
        ], Rules::messages() + [
            'contact_no.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
            'contact_no.unique' => 'This mobile number is already registered. Please log in instead.',
            'email.unique' => 'This email is already registered. Please log in instead.',
        ]);

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
            'user' => $user->load('resident'),
        ], 201);
    }

    /** Log in with an email address OR a resident's registered mobile number. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:255',
            'password' => 'required|string',
        ], [
            'login.required' => 'Enter your email or mobile number.',
        ]);

        $user = User::findByLogin($data['login']);

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['login' => 'Invalid email/mobile number or password.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This account has been deactivated. Please contact the administrator.']);
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

    /** The logged-in user, including the resident's profile picture (shown in the top bar). */
    public static function withPhoto(User $user): User
    {
        $user->load('resident');
        $user->resident?->makeVisible('photo');
        return $user;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }
}
