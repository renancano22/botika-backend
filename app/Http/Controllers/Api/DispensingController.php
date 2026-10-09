<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\MedicineRequest;
use App\Models\Resident;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\InventoryService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Patient and dispensing management (Objectives 2.3, 2.5, 3.2). */
class DispensingController extends Controller
{
    public function __construct(
        private InventoryService $inventory,
        private SmsService $sms,
        private ForecastService $forecast,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return Dispensing::with(['items.medicine:medicine_id,medicine_name,unit', 'request.resident', 'dispenser:user_id,name'])
            ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->whereHas('request',
                fn ($r) => $r->where('resident_id', $user->resident?->resident_id)))
            ->when($request->from, fn ($q, $d) => $q->whereDate('dispensed_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('dispensed_at', '<=', $d))
            ->orderByDesc('dispensed_at')
            ->limit(500)
            ->get();
    }

    /** Dispense an approved medicine request after the resident's QR code / Patient ID is verified. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'request_id' => 'required|exists:requests,request_id',
            'qr_code' => 'required|string',
        ]);

        $req = MedicineRequest::with(['items', 'resident'])->findOrFail($data['request_id']);

        if ($req->resident->qr_code !== trim($data['qr_code'])) {
            return response()->json(['message' => 'QR code / Patient ID does not match the requesting resident.'], 422);
        }
        if ($req->request_type !== 'medicine' || $req->status !== 'approved') {
            return response()->json(['message' => 'Only approved medicine requests can be dispensed.'], 422);
        }

        return response()->json($this->dispense($req, $request->user()), 201);
    }

    /** Walk-in: staff verifies the resident's QR code and dispenses directly (recorded as a request too). */
    public function walkIn(Request $request)
    {
        $data = $request->validate([
            'qr_code' => 'required|exists:residents,qr_code',
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'required|distinct|exists:medicines,medicine_id',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
        ]);

        $resident = Resident::where('qr_code', $data['qr_code'])->firstOrFail();

        // Walk-ins may only take stock that is not already reserved for approved requests.
        foreach ($data['items'] as $i => $item) {
            $free = $this->inventory->freeStock((int) $item['medicine_id']);
            if ($free < (int) $item['quantity']) {
                return response()->json([
                    'message' => "Only {$free} can be dispensed for item #" . ($i + 1) . '. The rest is reserved for approved requests.',
                ], 422);
            }
        }

        $dispensing = DB::transaction(function () use ($resident, $data, $request) {
            $req = MedicineRequest::create([
                'resident_id' => $resident->resident_id,
                'request_type' => 'medicine',
                'request_date' => now(),
                'status' => 'approved',
                'reviewed_by' => $request->user()->user_id,
                'reviewed_at' => now(),
                'remarks' => 'Walk-in',
            ]);
            $req->items()->createMany(array_map(fn ($i) => [
                'medicine_id' => (int) $i['medicine_id'],
                'quantity' => (int) $i['quantity'],
            ], $data['items']));

            return $this->dispense($req->load(['items', 'resident']), $request->user());
        });

        return response()->json($dispensing, 201);
    }

    private function dispense(MedicineRequest $req, User $staff): Dispensing
    {
        $dispensing = DB::transaction(function () use ($req, $staff) {
            $dispensing = Dispensing::create([
                'request_id' => $req->request_id,
                'dispensed_by' => $staff->user_id,
                'dispensed_at' => now(),
            ]);

            foreach ($req->items as $item) {
                $this->inventory->deduct($item->medicine_id, $item->quantity, $staff->user_id, $dispensing->dispensing_id);
            }

            $dispensing->items()->createMany($req->items->map->only(['medicine_id', 'quantity'])->all());
            $req->update(['status' => 'dispensed']);

            return $dispensing;
        });

        // Parallel steps after the inventory update (Activity Diagram): SMS + demand forecast refresh.
        $dispensing->load('items.medicine');
        $names = $dispensing->items->map(fn ($i) => "{$i->medicine->medicine_name} x{$i->quantity}")->implode(', ');
        $this->sms->notify($req->resident, "BulanBotikaCare: Medicines dispensed to you today: {$names}. Thank you!", $req->request_id, 'dispensed');

        try {
            foreach ($dispensing->items as $item) {
                $this->forecast->forecastMedicine($item->medicine);
            }
        } catch (Throwable $e) {
            Log::warning('Forecast refresh failed: ' . $e->getMessage());
        }

        return $dispensing->load(['request.resident', 'dispenser:user_id,name']);
    }
}
