<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResidentController extends Controller
{
    public function index(Request $request)
    {
        return Resident::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('qr_code', 'like', "%{$s}%")
                ->orWhere('contact_no', 'like', "%{$s}%")))
            ->orderBy('name')
            ->get();
    }

    /** QR Code / Resident ID verification before dispensing. */
    public function lookup(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $resident = Resident::where('qr_code', trim($request->code))->first();
        if (! $resident) {
            return response()->json(['message' => 'No resident found with that QR code / Resident ID.'], 404);
        }

        return $this->dispensingRecord($resident);
    }

    /**
     * "Forgot Resident ID or QR code?": find a resident by registered full name, optionally narrowed by barangay.
     * Returns only what is needed to pick the right person (name, Resident ID, barangay) — no mobile number or address.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\pL\s.'\-]+$/u"],
            'barangay' => ['nullable', 'string', Rule::in(config('botika.barangays'))],
        ], [
            'name.min' => 'Type at least 2 letters of the resident\'s name.',
            'name.regex' => 'The name can only have letters, spaces, periods, apostrophes and hyphens.',
            'barangay.in' => 'Choose a barangay from the list.',
        ]);

        // Every word typed must be part of the registered name ("juan cruz" finds "Juan Dela Cruz").
        $words = preg_split('/\s+/', trim($data['name']), -1, PREG_SPLIT_NO_EMPTY);
        $matches = Resident::query()
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where('name', 'like', '%' . addcslashes($word, '%_\\') . '%');
                }
            })
            ->when($data['barangay'] ?? null, fn ($q, $b) => $q->where('address', 'like', '%' . addcslashes($b, '%_\\') . ', Bulan%'))
            ->orderBy('name')
            ->limit(100)
            ->get(['resident_id', 'name', 'qr_code', 'address'])
            ->map(fn (Resident $r) => [
                'resident_id' => $r->resident_id,
                'name' => $r->name,
                'qr_code' => $r->qr_code,
                'barangay' => Resident::splitAddress($r->address)['barangay'],
            ])
            ->when($data['barangay'] ?? null, fn ($c, $b) => $c->where('barangay', $b))
            ->values();

        return ['results' => $matches->take(20)->values(), 'total' => $matches->count()];
    }

    /**
     * Confirms the identity of a resident picked from the name search. Picking a name is not enough:
     * the staff must confirm they checked the resident's identity in person (the Botika's identity check).
     * Only then is the resident's dispensing record returned.
     */
    public function verify(Request $request, Resident $resident)
    {
        $request->validate([
            'identity_confirmed' => ['accepted'],
        ], [
            'identity_confirmed.accepted' => 'Confirm that you checked the resident\'s identity.',
        ]);

        return $this->dispensingRecord($resident);
    }

    /** The verified resident, their approved medicine requests and their recent dispensing history. */
    private function dispensingRecord(Resident $resident): array
    {
        return [
            'resident' => $resident,
            'approved_requests' => $resident->requests()
                ->with(['items.medicine', 'prescription', 'prescriptionVerifier:user_id,name'])
                ->where('request_type', 'medicine')
                ->where('status', 'approved')
                ->orderBy('request_date')
                ->get(),
            'dispensing_history' => Dispensing::with(['items.medicine', 'dispenser:user_id,name'])
                ->whereHas('request', fn ($q) => $q->where('resident_id', $resident->resident_id))
                ->orderByDesc('dispensed_at')
                ->limit(20)
                ->get(),
        ];
    }
}
