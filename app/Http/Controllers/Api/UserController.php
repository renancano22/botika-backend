<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Rules;
use App\Support\Listing;
use App\Support\TemporaryPassword;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin: Manage User Accounts (create, view, edit, deactivate) - Fig. 4.4.
 *
 * Passwords: the administrator never chooses, sees or retrieves a user's password. When an account is
 * created, or its password is reset, the server generates a temporary password, stores only its hash and
 * returns the plain password once, in that response only. The user must change it at the first log-in,
 * and only they know the password they choose.
 */
class UserController extends Controller
{
    private const PASSWORD_PROHIBITED = 'Passwords cannot be set here. The system generates a temporary password (use Reset password).';

    /**
     * User accounts. Data table (?per_page=): one page, with ?role=, ?search= (name, email, mobile number or
     * Resident ID), ?status=active|inactive and ?sort=name|newest|oldest.
     */
    public function index(Request $request)
    {
        $search = Listing::search($request);
        $query = User::with('resident')
            ->when($request->role, fn ($q, $role) => $q->where('role', $role))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', Listing::like($search))->orWhere('email', 'like', Listing::like($search))
                ->orWhereHas('resident', fn ($r) => $r->where('contact_no', 'like', Listing::like($search))->orWhere('qr_code', 'like', Listing::like($search)))));

        if (Listing::wanted($request)) {
            $query->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false));
            match (Listing::sort($request, ['name', 'newest', 'oldest'])) {
                'name' => $query->orderBy('name')->orderBy('user_id'),
                'newest' => $query->orderByDesc('created_at')->orderByDesc('user_id'),
                'oldest' => $query->orderBy('created_at')->orderBy('user_id'),
            };
            return Listing::paginate($query, $request);
        }

        return $query->orderBy('name')->orderBy('user_id')->get();
    }

    public function store(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email'))), 'name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => Rules::name(),
            'email' => [...Rules::gmail(), 'unique:users,email'],
            'password' => 'prohibited',
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF])],
        ], Rules::messages() + ['password.prohibited' => self::PASSWORD_PROHIBITED]);

        // Temporary password: the new user must change it at the first log-in.
        $temporary = TemporaryPassword::generate([$data['name'], $data['email']]);
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']]);
        $user->setTemporaryPassword($temporary);
        $user->save();

        return $this->withTemporaryPassword($user, $temporary, 201);
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
            'password' => 'prohibited', // passwords are only changed by Reset password or by the user
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_RESIDENT])],
        ], Rules::messages() + ['password.prohibited' => self::PASSWORD_PROHIBITED]);

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
        $user->save();

        if ($user->resident) {
            $user->resident->update(['name' => $data['name']]);
        }

        return $user->load('resident');
    }

    /**
     * Reset password (e.g. a lost temporary password): a new temporary password replaces the old one, the
     * user is logged out everywhere and must change it at the next log-in. The old password is never shown.
     */
    public function resetPassword(Request $request, User $user)
    {
        // Own password: "Change password" in the menu, which asks for the current password.
        if ((int) $user->user_id === (int) $request->user()->user_id) {
            return response()->json(['message' => 'To change your own password, use "Change password" in the menu.'], 422);
        }
        if (! $user->is_active) {
            return response()->json(['message' => 'Activate this account first, then reset its password.'], 422);
        }

        $temporary = TemporaryPassword::generate([$user->name, $user->email, $user->resident?->contact_no], $user);
        $user->setTemporaryPassword($temporary);
        $user->save();
        $user->tokens()->delete();

        return $this->withTemporaryPassword($user, $temporary);
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

    /** The only responses that contain a password (shown once): never cached by the browser or a proxy. */
    private function withTemporaryPassword(User $user, string $temporary, int $status = 200)
    {
        return response()->json(['user' => $user->fresh('resident'), 'temporary_password' => $temporary], $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
