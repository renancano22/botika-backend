<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** User Management (DFD Process 1.0): resident registration, login and logout. */
class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'address' => 'required|string|max:255',
            'contact_no' => ['required', 'string', 'regex:/^(09|\+639)\d{9}$/'],
        ], [
            'contact_no.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
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

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Please contact the administrator.']);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $user->load('resident'),
        ]);
    }

    public function me(Request $request)
    {
        return $request->user()->load('resident');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }
}
