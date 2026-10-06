<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineRequest;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Medicine requests and restock requests (DFD Process 3.0, Activity Diagram Fig. 4.5).
 *  - Residents submit, update or cancel their own pending requests.
 *  - Pharmacy staff review/approve medicine requests.
 *  - The administrator reviews/approves restock requests.
 */
class RequestController extends Controller
{
    public function __construct(private InventoryService $inventory, private SmsService $sms) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return MedicineRequest::with(['items.medicine:medicine_id,medicine_name,unit', 'resident', 'reviewer:user_id,name'])
            ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->where('resident_id', $user->resident?->resident_id))
            ->when($request->type, fn ($q, $t) => $q->where('request_type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('request_date')
            ->limit(500)
            ->get();
    }

    public function store(Request $request)
    {
        $resident = $request->user()->resident;
        abort_unless($resident, 403, 'Only residents can submit requests.');

        $data = $this->validateItems($request, true);
        $this->checkAvailability($data['request_type'], $data['items']);

        $req = DB::transaction(function () use ($resident, $data) {
            $req = MedicineRequest::create([
                'resident_id' => $resident->resident_id,
                'request_type' => $data['request_type'],
                'request_date' => now(),
                'status' => 'pending',
            ]);
            $req->items()->createMany($data['items']);
            return $req;
        });

        return response()->json($req->load('items.medicine'), 201);
    }

    /** Resident: Cancel/Update Request (only while pending). */
    public function update(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeOwnPending($request, $medicineRequest);

        $data = $this->validateItems($request, false);
        $this->checkAvailability($medicineRequest->request_type, $data['items']);

        DB::transaction(function () use ($medicineRequest, $data) {
            $medicineRequest->items()->delete();
            $medicineRequest->items()->createMany($data['items']);
        });

        return $medicineRequest->load('items.medicine');
    }

    public function cancel(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeOwnPending($request, $medicineRequest);
        $medicineRequest->update(['status' => 'cancelled']);

        return $medicineRequest;
    }

    public function approve(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeReviewer($request, $medicineRequest);
        $medicineRequest->load(['items.medicine', 'resident']);

        if ($medicineRequest->request_type === 'medicine') {
            $this->checkAvailability('medicine', $medicineRequest->items->map->only(['medicine_id', 'quantity'])->all());
            $message = "BulanBotikaCare: Your medicine request #{$medicineRequest->request_id} has been APPROVED. "
                . "Please visit Botika ng Bayan Bulan and present your QR code / Patient ID ({$medicineRequest->resident->qr_code}).";
        } else {
            $message = "BulanBotikaCare: Your restock request #{$medicineRequest->request_id} has been approved. "
                . 'We will send you an SMS once the medicine is available.';
        }

        $medicineRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
        ]);
        $this->sms->notify($medicineRequest->resident, $message, $medicineRequest->request_id);

        // If the medicine was restocked while the request was waiting, mark it fulfilled right away.
        if ($medicineRequest->request_type === 'restock') {
            foreach ($medicineRequest->items as $item) {
                $this->inventory->fulfillRestockRequests($item->medicine_id);
            }
        }

        return $medicineRequest->fresh(['items.medicine', 'resident', 'reviewer:user_id,name']);
    }

    public function reject(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeReviewer($request, $medicineRequest);
        $data = $request->validate(['remarks' => 'required|string|max:255']);

        $medicineRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
            'remarks' => $data['remarks'],
        ]);

        $type = $medicineRequest->request_type === 'restock' ? 'restock' : 'medicine';
        $this->sms->notify(
            $medicineRequest->resident,
            "BulanBotikaCare: Your {$type} request #{$medicineRequest->request_id} was not approved. Reason: {$data['remarks']}",
            $medicineRequest->request_id
        );

        return $medicineRequest->fresh(['items.medicine', 'resident', 'reviewer:user_id,name']);
    }

    // ---------------------------------------------------------------- helpers

    private function validateItems(Request $request, bool $withType): array
    {
        $rules = [
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'required|distinct|exists:medicines,medicine_id',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
        ];
        if ($withType) {
            $rules['request_type'] = ['required', Rule::in(['medicine', 'restock'])];
        }

        $data = $request->validate($rules);
        $data['items'] = array_map(fn ($i) => [
            'medicine_id' => (int) $i['medicine_id'],
            'quantity' => (int) $i['quantity'],
        ], $data['items']);

        return $data;
    }

    /**
     * Medicine requests need free stock (not already reserved for other approved requests);
     * restock requests are only for medicines that are not available.
     */
    private function checkAvailability(string $type, array $items): void
    {
        foreach ($items as $i => $item) {
            $available = $this->inventory->freeStock($item['medicine_id']);

            if ($type === 'medicine' && $available < $item['quantity']) {
                throw ValidationException::withMessages([
                    "items.$i.quantity" => "Only {$available} available. You can submit a restock request instead.",
                ]);
            }
            if ($type === 'restock' && $available >= $item['quantity']) {
                throw ValidationException::withMessages([
                    "items.$i.medicine_id" => 'This medicine is available. Please submit a medicine request instead.',
                ]);
            }
        }
    }

    private function authorizeOwnPending(Request $request, MedicineRequest $req): void
    {
        abort_unless((int) $req->resident_id === (int) $request->user()->resident?->resident_id, 403, 'This is not your request.');
        abort_unless($req->status === 'pending', 422, 'Only pending requests can be changed.');
    }

    private function authorizeReviewer(Request $request, MedicineRequest $req): void
    {
        $role = $request->user()->role;
        if ($req->request_type === 'restock') {
            abort_unless($role === User::ROLE_ADMIN, 403, 'Only the administrator can review restock requests.');
        }
        abort_unless($req->status === 'pending', 422, 'Only pending requests can be reviewed.');
    }
}
