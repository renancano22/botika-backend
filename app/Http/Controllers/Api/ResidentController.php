<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\Resident;
use App\Services\MonthlyLimit;
use Illuminate\Http\Request;

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

    /** QR Code / Patient ID verification before dispensing. */
    public function lookup(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $resident = Resident::where('qr_code', trim($request->code))->first();
        if (! $resident) {
            return response()->json(['message' => 'No resident found with that QR code / Patient ID.'], 404);
        }

        return [
            'resident' => $resident,
            // Monthly limit: how much this resident has received / has set aside this month.
            'monthly' => app(MonthlyLimit::class)->usage($resident->resident_id),
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
