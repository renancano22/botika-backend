<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Resident;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\InventoryService;
use App\Services\SmsService;
use App\Support\Listing;
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

    /**
     * Dispensing records (residents: their own medicine history), newest first.
     * Data table (?per_page=): one page, with ?search= (request no., resident name or ID, medicine),
     * ?from= / ?to= (date dispensed), ?sort=newest|oldest, and a summary of ALL matching records
     * (number of records, residents served, total quantity), not only of the page shown.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (Listing::wanted($request)) {
            Listing::validateDates($request);
            $search = Listing::search($request);
            $base = Dispensing::query()
                ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->whereHas('request',
                    fn ($r) => $r->where('resident_id', $user->resident?->resident_id)))
                ->when(Listing::number($request) !== null, fn ($q) => $q->where('request_id', Listing::number($request)))
                ->when($search !== '' && Listing::number($request) === null, fn ($q) => $q->where(fn ($w) => $w
                    ->when(ctype_digit($search), fn ($x) => $x->orWhere('request_id', (int) $search))
                    ->when($user->role !== User::ROLE_RESIDENT, fn ($x) => $x->orWhereHas('request.resident', fn ($r) => $r
                        ->where('name', 'like', Listing::like($search))->orWhere('qr_code', 'like', Listing::like($search))))
                    ->orWhereHas('items.medicine', fn ($m) => $m->where('medicine_name', 'like', Listing::like($search)))));
            Listing::dateRange($base, $request, 'dispensed_at');

            $summary = [
                'records' => (clone $base)->count(),
                'residents' => MedicineRequest::whereIn('request_id', (clone $base)->select('request_id'))->distinct()->count('resident_id'),
                'total_quantity' => (int) DispensingItem::whereIn('dispensing_id', (clone $base)->select('dispensing_id'))->sum('quantity'),
            ];
            $dir = Listing::sort($request, ['newest', 'oldest']) === 'oldest' ? 'asc' : 'desc';
            $query = (clone $base)->with(['items.medicine:medicine_id,medicine_name,unit', 'request.resident', 'dispenser:user_id,name'])
                ->orderBy('dispensed_at', $dir)->orderBy('dispensing_id', $dir);

            return Listing::paginate($query, $request, null, ['summary' => $summary]);
        }

        return Dispensing::with(['items.medicine:medicine_id,medicine_name,unit', 'request.resident', 'dispenser:user_id,name'])
            ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->whereHas('request',
                fn ($r) => $r->where('resident_id', $user->resident?->resident_id)))
            ->when($request->from, fn ($q, $d) => $q->whereDate('dispensed_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('dispensed_at', '<=', $d))
            ->orderByDesc('dispensed_at')
            ->limit(500)
            ->get();
    }

    /** Dispense an approved medicine request after the resident's QR code / Resident ID is verified. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'request_id' => 'required|exists:requests,request_id',
            'qr_code' => 'required|string|max:50',
        ]);

        $req = MedicineRequest::with(['items', 'resident'])->findOrFail($data['request_id']);

        if ($req->resident->qr_code !== trim($data['qr_code'])) {
            return response()->json(['message' => 'QR code / Resident ID does not match the requesting resident.'], 422);
        }
        if ($req->request_type !== 'medicine' || $req->status !== 'approved') {
            return response()->json(['message' => 'Only approved medicine requests can be dispensed.'], 422);
        }
        $rx = $req->prescriptionMedicines();
        if ($rx->isNotEmpty() && ! $request->boolean('prescription_checked')) {
            return response()->json(['message' => self::rxMessage($rx->implode(', '))], 422);
        }

        return response()->json($this->dispense($req, $request->user(), $rx->isNotEmpty()), 201);
    }

    /** Walk-in: staff verifies the resident's QR code and dispenses directly (recorded as a request too). */
    public function walkIn(Request $request)
    {
        $data = $request->validate([
            'qr_code' => 'required|exists:residents,qr_code',
            'items' => 'required|array|min:1|max:20',
            'items.*.medicine_id' => 'required|distinct|exists:medicines,medicine_id',
            'items.*.quantity' => 'required|integer|min:1|max:' . config('botika.max_request_quantity'),
        ], [
            'items.*.quantity.max' => 'At most :max can be given per medicine.',
            'items.*.medicine_id.distinct' => 'This medicine is already in the list.',
            'items.*.medicine_id.required' => 'Choose a medicine for each row.',
            'items.required' => 'Add at least one medicine.',
            'items.max' => 'At most 20 medicines can be dispensed at once.',
            'qr_code.exists' => 'No resident found with this Resident ID.',
        ]);

        $resident = Resident::where('qr_code', $data['qr_code'])->firstOrFail();

        $rx = Medicine::whereIn('medicine_id', array_column($data['items'], 'medicine_id'))->where('requires_prescription', true)->pluck('medicine_name');
        if ($rx->isNotEmpty() && ! $request->boolean('prescription_checked')) {
            return response()->json(['message' => self::rxMessage($rx->implode(', '))], 422);
        }

        // Walk-ins may only take stock that is not already reserved for approved requests.
        foreach ($data['items'] as $i => $item) {
            $free = $this->inventory->freeStock((int) $item['medicine_id']);
            if ($free < (int) $item['quantity']) {
                return response()->json([
                    'message' => "Only {$free} can be dispensed for item #" . ($i + 1) . '. The rest is reserved for approved requests.',
                ], 422);
            }
        }

        $dispensing = DB::transaction(function () use ($resident, $data, $request, $rx) {
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

            return $this->dispense($req->load(['items', 'resident']), $request->user(), $rx->isNotEmpty());
        });

        return response()->json($dispensing, 201);
    }

    private static function rxMessage(string $names): string
    {
        return "Prescription medicine ({$names}): check the resident's original doctor's prescription, then confirm before releasing.";
    }

    private function dispense(MedicineRequest $req, User $staff, bool $prescriptionChecked = false): Dispensing
    {
        $dispensing = DB::transaction(function () use ($req, $staff, $prescriptionChecked) {
            $dispensing = Dispensing::create([
                'request_id' => $req->request_id,
                'dispensed_by' => $staff->user_id,
                'dispensed_at' => now(),
                'prescription_checked' => $prescriptionChecked,
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
        $this->sms->notify(
            $req->resident,
            "BulanBotikaCare: Request No. {$req->request_id} is COMPLETED. You received {$names} at Botika ng Bayan Bulan on "
                . MedicineRequest::noticeDate($dispensing->dispensed_at) . '. No further action is needed. Thank you!',
            $req->request_id,
            'dispensed'
        );

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
