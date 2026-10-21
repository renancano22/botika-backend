<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Prescription;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PermissionService;
use App\Services\SmsService;
use App\Support\Listing;
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
    /** Everything the request status tracker shows (who reviewed, dispensed or cancelled it, and when). */
    public const DETAILS = [
        'items.medicine:medicine_id,medicine_name,unit,requires_prescription', 'resident',
        'prescription', 'prescriptionVerifier:user_id,name', 'reviewer:user_id,name', 'canceller:user_id,name',
        'dispensing:dispensing_id,request_id,dispensed_by,dispensed_at', 'dispensing.dispenser:user_id,name',
    ];

    public function __construct(private InventoryService $inventory, private SmsService $sms) {}

    /**
     * Requests list, newest first. Residents only get their own requests.
     * Data table (?per_page=): one page, with ?search= (request no., resident name or ID, medicine),
     * ?from= / ?to= (request date), ?type=, ?status= and ?sort=newest|oldest.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (Listing::wanted($request)) {
            Listing::validateDates($request);
            $dir = Listing::sort($request, ['newest', 'oldest']) === 'oldest' ? 'asc' : 'desc';
            $query = $this->filtered($request)->with(self::DETAILS)->orderBy('request_date', $dir)->orderBy('request_id', $dir);
            return Listing::paginate($query, $request);
        }

        return $this->filtered($request)->with(self::DETAILS)
            ->orderByDesc('request_date')->orderByDesc('request_id')
            ->when($user->role !== User::ROLE_RESIDENT, fn ($q) => $q->limit(500)) // residents get all of their own requests
            ->get();
    }

    /** Staff/admin: number of requests in each status, e.g. {"pending": 3, "approved": 5, ...}, with the same filters as the list. */
    public function counts(Request $request)
    {
        Listing::validateDates($request);
        $counts = $this->filtered($request, false)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        return $counts->put('all', $counts->sum());
    }

    /** The filters shared by the list and the status counts (a resident only ever sees their own requests). */
    private function filtered(Request $request, bool $withStatus = true)
    {
        $user = $request->user();
        $search = Listing::search($request);

        $query = MedicineRequest::query()
            ->when($user->role === User::ROLE_RESIDENT, fn ($q) => $q->where('resident_id', $user->resident?->resident_id))
            ->when($request->type, fn ($q, $t) => $q->where('request_type', $t))
            ->when($withStatus && $request->status, fn ($q) => $q->where('status', $request->status))
            ->when(Listing::number($request) !== null, fn ($q) => $q->where('request_id', Listing::number($request)))
            ->when($search !== '' && Listing::number($request) === null, fn ($q) => $q->where(fn ($w) => $w
                ->when(ctype_digit($search), fn ($x) => $x->orWhere('request_id', (int) $search))
                ->orWhereHas('resident', fn ($r) => $r->where('name', 'like', Listing::like($search))->orWhere('qr_code', 'like', Listing::like($search)))
                ->orWhereHas('items.medicine', fn ($m) => $m->where('medicine_name', 'like', Listing::like($search)))));

        return Listing::dateRange($query, $request, 'request_date');
    }

    /** One request with its full status history (residents can only open their own). */
    public function show(Request $request, MedicineRequest $medicineRequest)
    {
        $user = $request->user();
        if ($user->role === User::ROLE_RESIDENT) {
            abort_unless((int) $medicineRequest->resident_id === (int) $user->resident?->resident_id, 404);
        }

        return $medicineRequest->load(self::DETAILS);
    }

    public function store(Request $request)
    {
        $resident = $request->user()->resident;
        abort_unless($resident, 403, 'Only residents can submit requests.');

        // User Permissions (checked before the form, so a blocked user gets "Access denied", not a form error):
        // medicine and restock requests can be turned off separately by the administrator.
        $feature = $request->input('request_type') === 'restock' ? 'restock.create' : 'requests.create';
        if (! $request->user()->hasPermission($feature)) {
            $label = app(PermissionService::class)->label(User::ROLE_RESIDENT, $feature);
            abort(403, "Access denied. You do not have permission to use \"{$label}\". Please contact the administrator if you need access.");
        }
        $data = $this->validateItems($request, true);
        // Medicines that need a doctor's prescription: a photo/PDF of it must be uploaded with the request.
        $rxNames = $data['request_type'] === 'medicine'
            ? Medicine::whereIn('medicine_id', array_column($data['items'], 'medicine_id'))->where('requires_prescription', true)->pluck('medicine_name')
            : collect();
        $prescription = $rxNames->isNotEmpty() ? $this->validatePrescription($request, $rxNames->implode(', ')) : null;
        $note = $request->validate(['note' => 'nullable|string|max:500'], [
            'note.max' => 'Please keep your note under 500 characters.',
        ])['note'] ?? null;
        if ($data['request_type'] === 'restock') {
            $this->checkNoOpenRestock($resident->resident_id, array_column($data['items'], 'medicine_id'));
        } else {
            $this->checkNoOpenMedicineRequest($resident->resident_id, array_column($data['items'], 'medicine_id'));
        }
        $this->checkAvailability($data['request_type'], $data['items']);

        $req = DB::transaction(function () use ($resident, $data, $note, $prescription) {
            $req = MedicineRequest::create([
                'resident_id' => $resident->resident_id,
                'request_type' => $data['request_type'],
                'request_date' => now(),
                'resident_note' => $note ? trim($note) : null,
                'status' => 'pending',
            ]);
            $req->items()->createMany($data['items']);
            if ($prescription) {
                $req->prescription()->create($prescription);
            }
            return $req;
        });

        // In-app confirmation only (no SMS, to save SMS credits).
        $reviewer = $data['request_type'] === 'restock' ? 'the administrator' : 'the pharmacy staff';
        $this->sms->notifyInApp(
            $resident,
            "Your {$data['request_type']} request No. {$req->request_id} ({$req->itemsSummary()}) was submitted successfully "
                . "and is now waiting for review by {$reviewer}. You will be notified once it is approved or not approved. "
                . 'No action is needed for now.',
            $req->request_id,
            'submitted'
        );

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

    /**
     * Resident: cancel their own request while it is pending, or approved but not yet claimed.
     * Cancelling an approved medicine request gives its set-aside medicine back to the stock.
     */
    public function cancel(Request $request, MedicineRequest $medicineRequest)
    {
        abort_unless((int) $medicineRequest->resident_id === (int) $request->user()->resident?->resident_id, 403, 'This is not your request.');
        abort_unless(in_array($medicineRequest->status, ['pending', 'approved'], true), 422,
            'Only pending or approved (not yet claimed) requests can be cancelled.');

        $wasReserved = $medicineRequest->status === 'approved' && $medicineRequest->request_type === 'medicine';

        $medicineRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->user_id,
        ]);

        if ($wasReserved) {
            $this->inventory->releaseReserved($medicineRequest->items()->pluck('medicine_id'));
        }

        // In-app confirmation only (the resident cancelled it themselves).
        $this->sms->notifyInApp(
            $medicineRequest->resident,
            "You cancelled your {$medicineRequest->request_type} request No. {$medicineRequest->request_id} "
                . "({$medicineRequest->itemsSummary()}) on " . MedicineRequest::noticeDate(now()) . '. '
                . ($wasReserved ? 'The medicine set aside for you was returned to the stock. ' : '')
                . 'This request is now closed. You may submit a new request anytime if you still need the medicine.',
            $medicineRequest->request_id,
            'cancelled'
        );

        return $medicineRequest->fresh(self::DETAILS);
    }

    public function approve(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeReviewer($request, $medicineRequest);
        $medicineRequest->load(['items.medicine', 'resident', 'prescription']);

        // Prescription medicines: the staff must check the uploaded prescription before approving.
        $rxNames = $medicineRequest->request_type === 'medicine' ? $medicineRequest->prescriptionMedicines() : collect();
        if ($rxNames->isNotEmpty()) {
            if (! $medicineRequest->prescription) {
                throw ValidationException::withMessages(['prescription' => 'No prescription was uploaded for ' . $rxNames->implode(', ') . '. Reject the request or ask the resident to upload one.']);
            }
            if (! $request->boolean('prescription_verified')) {
                throw ValidationException::withMessages(['prescription_verified' => 'Please check the prescription and confirm it is valid before approving.']);
            }
        }

        if ($medicineRequest->request_type === 'medicine') {
            $this->checkAvailability('medicine', $medicineRequest->items->map->only(['medicine_id', 'quantity'])->all());
            // Pickup date = the approval date; the resident has botika.unclaimed_days (7) days to claim it.
            $pickup = MedicineRequest::noticeDate(now());
            $deadline = MedicineRequest::noticeDate(MedicineRequest::claimDeadline(now()));
            $days = config('botika.unclaimed_days');
            $message = "BulanBotikaCare: Request No. {$medicineRequest->request_id} is APPROVED and ready for pickup at Botika ng Bayan Bulan "
                . "({$medicineRequest->itemsSummary()}). Pickup date: {$pickup}. Claim it on or before {$deadline} ({$days} days). "
                . "Bring your Resident ID/QR {$medicineRequest->resident->qr_code} and a valid ID or Barangay ID. After the deadline, the medicine will no longer be given.";
        } else {
            $message = "BulanBotikaCare: Your restock request No. {$medicineRequest->request_id} ({$medicineRequest->itemsSummary()}) is APPROVED. "
                . 'We will notify you once the medicine is available at Botika ng Bayan Bulan. No action is needed for now.';
        }

        $medicineRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
            'remarks' => $request->input('remarks'),
            'prescription_verified_at' => $rxNames->isNotEmpty() ? now() : null,
            'prescription_verified_by' => $rxNames->isNotEmpty() ? $request->user()->user_id : null,
        ]);
        $this->sms->notify($medicineRequest->resident, $message, $medicineRequest->request_id, 'approved');

        // If the medicine was restocked while the request was waiting, mark it fulfilled right away.
        if ($medicineRequest->request_type === 'restock') {
            foreach ($medicineRequest->items as $item) {
                $this->inventory->fulfillRestockRequests($item->medicine_id);
            }
        }

        return $medicineRequest->fresh(self::DETAILS);
    }

    public function reject(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeReviewer($request, $medicineRequest);
        $data = $request->validate(['remarks' => 'required|string|min:3|max:255'], [
            'remarks.required' => 'Give the reason for rejecting the request.',
            'remarks.min' => 'Please describe the reason (at least 3 characters).',
        ]);

        $medicineRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->user_id,
            'reviewed_at' => now(),
            'remarks' => $data['remarks'],
        ]);

        $type = $medicineRequest->request_type === 'restock' ? 'restock' : 'medicine';
        $this->sms->notify(
            $medicineRequest->resident,
            "BulanBotikaCare: Your {$type} request No. {$medicineRequest->request_id} ({$medicineRequest->itemsSummary()}) was NOT APPROVED. "
                . 'Reason: ' . rtrim(trim($data['remarks']), '.') . '. You may submit a new request if you still need the medicine.',
            $medicineRequest->request_id,
            'rejected'
        );

        return $medicineRequest->fresh(self::DETAILS);
    }

    // ---------------------------------------------------------------- helpers

    private function validateItems(Request $request, bool $withType): array
    {
        $rules = [
            'items' => 'required|array|min:1|max:20',
            'items.*.medicine_id' => 'required|distinct|exists:medicines,medicine_id',
            'items.*.quantity' => 'required|integer|min:1|max:' . config('botika.max_request_quantity'),
        ];
        if ($withType) {
            $rules['request_type'] = ['required', Rule::in(['medicine', 'restock'])];
        }

        $data = $request->validate($rules, [
            'items.*.quantity.max' => 'You can request at most :max per medicine.',
            'items.*.quantity.min' => 'The quantity must be at least 1.',
            'items.*.quantity.integer' => 'The quantity must be a whole number.',
            'items.*.medicine_id.distinct' => 'This medicine is already in your request.',
            'items.required' => 'Add at least one medicine.',
            'items.min' => 'Add at least one medicine.',
            'items.max' => 'One request can have at most 20 medicines.',
        ]);
        $data['items'] = array_map(fn ($i) => [
            'medicine_id' => (int) $i['medicine_id'],
            'quantity' => (int) $i['quantity'],
        ], $data['items']);

        return $data;
    }

    /**
     * One open medicine request per medicine: if the resident already asked for this medicine and the
     * request is still pending, they edit that request instead of making a new one (all in one group).
     * If it is already approved, they claim it first (or cancel it and send a new request).
     */
    private function checkNoOpenMedicineRequest(int $residentId, array $medicineIds): void
    {
        $open = MedicineRequest::with('items.medicine:medicine_id,medicine_name')
            ->where('resident_id', $residentId)
            ->where('request_type', 'medicine')
            ->whereIn('status', ['pending', 'approved'])
            ->whereHas('items', fn ($q) => $q->whereIn('medicine_id', $medicineIds))
            ->orderBy('request_date')
            ->first();
        if (! $open) {
            return;
        }

        $name = $open->items->whereIn('medicine_id', $medicineIds)->first()?->medicine?->medicine_name ?? 'this medicine';
        throw ValidationException::withMessages([
            'items' => $open->status === 'pending'
                ? "You already requested {$name} in request No. {$open->request_id}. Please edit that request instead of making a new one."
                : "{$name} is already approved for you in request No. {$open->request_id} and is ready for pickup. Please claim it first.",
            'open_request_id' => (string) $open->request_id,
        ]);
    }

    /**
     * One restock request per medicine at a time: while a resident's restock request for a medicine is
     * still open (pending, or approved and waiting for stock), they cannot send another one for it.
     */
    private function checkNoOpenRestock(int $residentId, array $medicineIds): void
    {
        $open = MedicineRequest::with('items.medicine:medicine_id,medicine_name')
            ->where('resident_id', $residentId)
            ->where('request_type', 'restock')
            ->whereIn('status', ['pending', 'approved'])
            ->whereHas('items', fn ($q) => $q->whereIn('medicine_id', $medicineIds))
            ->orderBy('request_date')
            ->first();
        if (! $open) {
            return;
        }

        $name = $open->items->whereIn('medicine_id', $medicineIds)->first()?->medicine?->medicine_name ?? 'this medicine';
        throw ValidationException::withMessages([
            'items' => "You already submitted a restock request for {$name} (request No. {$open->request_id}). We will notify you when the medicine is available.",
        ]);
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

    /** Resident: replace the prescription of a pending request (e.g. the first photo was unclear). */
    public function uploadPrescription(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeOwnPending($request, $medicineRequest);
        $file = $this->validatePrescription($request, 'this request');

        $medicineRequest->prescription()->delete();
        $medicineRequest->prescription()->create($file);

        return $medicineRequest->fresh(self::DETAILS);
    }

    /** The prescription file (for the resident who uploaded it, and for staff/admin). */
    public function showPrescription(Request $request, MedicineRequest $medicineRequest)
    {
        $user = $request->user();
        if ($user->role === User::ROLE_RESIDENT) {
            abort_unless((int) $medicineRequest->resident_id === (int) $user->resident?->resident_id, 404);
        }
        $p = $medicineRequest->prescription;
        abort_unless($p, 404, 'No prescription was uploaded for this request.');

        return [
            'file_name' => $p->file_name,
            'mime_type' => $p->mime_type,
            'data' => 'data:' . $p->mime_type . ';base64,' . $p->file_data,
        ];
    }

    /**
     * Validates an uploaded prescription sent as { name, data: "data:<type>;base64,..." }.
     * @return array{file_name: string, mime_type: string, size: int, file_data: string, uploaded_at: \Illuminate\Support\Carbon}
     */
    private function validatePrescription(Request $request, string $forWhat): array
    {
        $input = $request->input('prescription');
        if (! is_array($input) || empty($input['data'])) {
            throw ValidationException::withMessages([
                'prescription' => "A doctor's prescription is required for {$forWhat}. Please upload a clear photo or PDF of it.",
            ]);
        }

        // "data:image/jpeg;base64,...." — check the type, then that the rest is plain base64 text.
        $data = (string) $input['data'];
        $comma = strpos($data, ',');
        $isFile = $comma !== false && $comma < 100
            && preg_match('#^data:([a-z/+.-]+);base64$#', substr($data, 0, $comma), $m)
            && in_array($m[1], Prescription::MIME_TYPES, true);
        $base64 = $isFile ? substr($data, $comma + 1) : '';
        if (! $isFile || $base64 === '' || strspn($base64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($base64)) {
            throw ValidationException::withMessages(['prescription' => 'The prescription must be a JPG, PNG or PDF file.']);
        }
        $size = (int) floor(strlen($base64) * 3 / 4);
        if ($size > Prescription::MAX_BYTES) {
            throw ValidationException::withMessages(['prescription' => 'The prescription file is too large (4 MB at most).']);
        }

        return [
            'file_name' => mb_substr(trim((string) ($input['name'] ?? '')) ?: 'prescription', 0, 200),
            'mime_type' => $m[1],
            'size' => $size,
            'file_data' => $base64,
            'uploaded_at' => now(),
        ];
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
