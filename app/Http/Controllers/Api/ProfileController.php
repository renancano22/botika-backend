<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Resident "My Profile": view and edit their own information, profile picture and password.
 * The QR code / Patient ID is shown here too (it never changes).
 */
class ProfileController extends Controller
{
    /** Profile pictures are resized in the browser to a small JPEG/PNG/WebP before upload. */
    private const PHOTO_MAX_CHARS = 400000; // about 300 KB

    public function show(Request $request)
    {
        return $this->profile($request);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $resident = $user->resident;

        $request->merge([
            'contact_no' => Resident::normalizePhone($request->input('contact_no')),
            'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null,
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'name' => Rules::name(),
            'email' => [...Rules::gmail(false), Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/',
                Rule::unique('residents', 'contact_no')->ignore($resident->resident_id, 'resident_id')],
            'barangay' => Rules::barangay(),
            'address_line' => ['nullable', 'string', 'max:120'],
        ], Rules::messages() + [
            'contact_no.regex' => 'Enter a valid mobile number, e.g. 09171234567.',
            'contact_no.unique' => 'This mobile number is already used by another account.',
            'email.unique' => 'This email is already used by another account.',
        ]);

        DB::transaction(function () use ($user, $resident, $data) {
            $user->update(['name' => $data['name'], 'email' => $data['email'] ?? null]);
            $resident->update([
                'name' => $data['name'],
                'contact_no' => $data['contact_no'],
                'address' => Resident::composeAddress($data['address_line'] ?? null, $data['barangay']),
            ]);
        });

        return $this->profile($request);
    }

    public function updatePhoto(Request $request)
    {
        $data = $request->validate([
            'photo' => ['required', 'string', 'max:' . self::PHOTO_MAX_CHARS,
                'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'],
        ], [
            'photo.max' => 'The picture is too large. Please choose a smaller one.',
            'photo.regex' => 'Please choose a JPG, PNG or WebP picture.',
        ]);

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
            'password' => [...Rules::password(), 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->password_hash = $data['password'];
        $user->save();

        // Log out other phones/computers that used the old password; this one stays logged in.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

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
