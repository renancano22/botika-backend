<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: Manage User Accounts (create, view, edit, deactivate) - Fig. 4.4. */
class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::with('resident')
            ->when($request->role, fn ($q, $role) => $q->where('role', $role))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->orWhereHas('resident', fn ($r) => $r->where('contact_no', 'like', "%{$s}%")->orWhere('qr_code', 'like', "%{$s}%"))))
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email'))), 'name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => Rules::name(),
            'email' => [...Rules::gmail(), 'unique:users,email'],
            'password' => Rules::password(),
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
        ], Rules::messages());

        return response()->json(User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => $data['password'],
            'role' => $data['role'],
        ]), 201);
    }

    public function update(Request $request, User $user)
    {
        $request->merge([
            'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null,
            'name' => trim((string) $request->input('name')),
        ]);
        $data = $request->validate([
            'name' => Rules::name(),
            // Residents may have no email (they log in with their mobile number); staff and admin need one.
            'email' => [...Rules::gmail($user->role !== User::ROLE_RESIDENT), Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            'password' => Rules::password(false),
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_RESIDENT])],
        ], Rules::messages());

        // A resident account must stay a resident (it is linked to a resident profile).
        if ($user->role === User::ROLE_RESIDENT && $data['role'] !== User::ROLE_RESIDENT) {
            return response()->json(['message' => 'Resident accounts cannot be changed to staff or admin.'], 422);
        }
        // Staff/admin accounts cannot become residents (they have no resident profile).
        if ($user->role !== User::ROLE_RESIDENT && $data['role'] === User::ROLE_RESIDENT) {
            return response()->json(['message' => 'Staff and administrator accounts cannot be changed to resident.'], 422);
        }
        // Prevents the administrator from locking themselves out of the admin pages.
        if ((int) $user->user_id === (int) $request->user()->user_id && $data['role'] !== $user->role) {
            return response()->json(['message' => 'You cannot change the role of your own account.'], 422);
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email'] ?? null, 'role' => $data['role']]);
        if (! empty($data['password'])) {
            $user->password_hash = $data['password'];
        }
        $user->save();

        if ($user->resident) {
            $user->resident->update(['name' => $data['name']]);
        }

        return $user->load('resident');
    }

    public function toggleActive(Request $request, User $user)
    {
        if ((int) $user->user_id === (int) $request->user()->user_id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete(); // log the user out everywhere
        }

        return $user;
    }
}
