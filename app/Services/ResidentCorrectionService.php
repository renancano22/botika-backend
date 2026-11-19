<?php

namespace App\Services;

use App\Models\Resident;
use App\Models\ResidentCorrection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResidentCorrectionService
{
    /** Both correction endpoints use the same checks, transaction, audit and notification. */
    public function correct(Request $request, Resident $target, array $data, bool $includeEmail = false): Resident
    {
        abort_unless($request->user()?->role === User::ROLE_ADMIN, 403);

        return DB::transaction(function () use ($request, $target, $data, $includeEmail) {
            $resident = Resident::whereKey($target->resident_id)->lockForUpdate()->firstOrFail();
            $user = $resident->user_id ? User::whereKey($resident->user_id)->lockForUpdate()->firstOrFail() : null;
            $before = ['name' => $resident->name, 'barangay' => Resident::splitAddress($resident->address)['barangay']];
            $after = ['name' => preg_replace('/\s+/u', ' ', trim($data['name'])), 'barangay' => ($data['barangay'] ?? null) ?: $before['barangay']];
            if ($includeEmail && $user) {
                $before['email'] = $user->email;
                $after['email'] = $data['email'] ?? null;
            }
            $changes = [];
            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) $changes[$field] = ['from' => $before[$field], 'to' => $value];
            }
            // An identical retry does not create duplicate history or notifications.
            if (! $changes) return $resident;

            if (is_string($request->input('correction_reason'))) {
                $request->merge(['correction_reason' => preg_replace('/\s+/u', ' ', trim($request->input('correction_reason')))]);
            }
            $verification = $request->validate([
                'identity_verified' => ['required', 'accepted'],
                'verification_method' => ['required', Rule::in(['valid_id', 'barangay_id'])],
                'correction_reason' => ['required', 'string', 'min:10', 'max:500', 'regex:/\pL/u'],
            ], [
                'identity_verified.required' => 'Confirm that you checked the resident’s ID and requested correction.',
                'identity_verified.accepted' => 'Confirm that you checked the resident’s ID and requested correction.',
                'correction_reason.min' => 'Explain the correction using at least 10 characters.',
            ]);

            if (isset($changes['name']) || isset($changes['barangay'])) {
                $request->validate(['barangay' => ['required', Rule::in(config('botika.barangays'))]]);
                if ($error = $resident->correctIdentity($after['name'], $after['barangay'])) {
                    throw ValidationException::withMessages(['name' => [$error]]);
                }
            }
            if ($user && $includeEmail) $user->update(['email' => $after['email']]);

            ResidentCorrection::create([
                'resident_id' => $resident->resident_id,
                'resident_code' => $resident->qr_code,
                'actor_user_id' => $request->user()->user_id,
                'actor_name' => $request->user()->name,
                'verification_method' => $verification['verification_method'],
                'reason' => $verification['correction_reason'],
                'changes' => $changes,
                'verified_at' => now(),
                'created_at' => now(),
            ]);
            $labels = ['name' => 'full name', 'email' => 'email address', 'barangay' => 'barangay'];
            $fields = implode(', ', array_map(fn ($field) => $labels[$field], array_keys($changes)));
            // Kept in the same transaction: a failed audit or notice rolls the correction back.
            app(SmsService::class)->notifyInApp($resident,
                "An administrator corrected your {$fields} after an identity check. Your Resident ID and medicine history stay the same. If you did not request this correction, contact Botika ng Bayan, Bulan immediately.",
                null, 'account_updated');

            return $resident->fresh();
        });
    }
}
