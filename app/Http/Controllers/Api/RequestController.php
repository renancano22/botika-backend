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
use App\Services\StaffNotifier;
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
        'prescription', 'prescriptions', 'prescriptionVerifier:user_id,name', 'reviewer:user_id,name', 'canceller:user_id,name',
        'dispensing:dispensing_id,request_id,dispensed_by,dispensed_at', 'dispensing.dispenser:user_id,name',
    ];

    public function __construct(private InventoryService $inventory, private SmsService $sms, private StaffNotifier $staff) {}

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
            // Staff/admin: approved requests whose set-aside medicine expires before pickup (or is no longer enough).
            $risks = $user->role !== User::ROLE_RESIDENT ? $this->inventory->pickupRisks() : collect();
            return Listing::paginate($query, $request, fn ($rows) => $rows->each(fn ($r) => $r->setAttribute('stock_warnings', $risks->get($r->request_id))));
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
        $rxIds = $data['request_type'] === 'medicine'
            ? Medicine::whereIn('medicine_id', array_column($data['items'], 'medicine_id'))->where('requires_prescription', true)->pluck('medicine_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $prescriptions = $this->validatePrescriptions($request, $rxIds);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/']], [
            'note.max' => 'Please keep your note under 500 characters.',
            'note.not_regex' => 'Remove unsupported control characters from your note.',
        ])['note'] ?? null;
        if ($data['request_type'] === 'restock') {
            $this->checkNoOpenRestock($resident->resident_id, array_column($data['items'], 'medicine_id'));
        } else {
            $this->checkNoOpenMedicineRequest($resident->resident_id, array_column($data['items'], 'medicine_id'));
        }
        $this->checkAvailability($data['request_type'], $data['items']);

        $req = DB::transaction(function () use ($resident, $data, $note, $prescriptions) {
            $req = MedicineRequest::create([
                'resident_id' => $resident->resident_id,
                'request_type' => $data['request_type'],
                'request_date' => now(),
                'resident_note' => $note ? trim($note) : null,
                'status' => 'pending',
            ]);
            $req->items()->createMany($data['items']);
            $req->prescriptions()->createMany($prescriptions);
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
        // The pharmacy staff / administrator are notified in the app (the bell): a new request to review.
        $this->staff->requestSubmitted($req);

        return response()->json($req->load(self::DETAILS), 201);
    }

    /** Resident: Cancel/Update Request (only while pending). */
    public function update(Request $request, MedicineRequest $medicineRequest)
    {
        $this->authorizeOwnPending($request, $medicineRequest);
        // A restock request has no quantity: there is nothing to change (the resident can cancel it instead).
        abort_if($medicineRequest->request_type === 'restock', 422, 'A restock request has no quantity to change. You can cancel it instead.');

        $data = $this->validateItems($request, false);
        $existingIds = $medicineRequest->items()->pluck('medicine_id')->all();
        if (array_diff(array_column($data['items'], 'medicine_id'), $existingIds)) {
            throw ValidationException::withMessages(['items' => 'Submit a new request to add a different medicine and its prescription. You can only change quantities or remove medicines here.']);
        }
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
        $this->staff->requestCancelled($medicineRequest, $wasReserved);

        return $medicineRequest->fresh(self::DETAILS);
    }

    public function approve(Request $request, MedicineRequest $medicineRequest)
    {
        [$medicineRequest, $message] = DB::transaction(function () use ($request, $medicineRequest) {
            $medicineRequest = MedicineRequest::whereKey($medicineRequest->request_id)->lockForUpdate()->firstOrFail();
            $this->authorizeReviewer($request, $medicineRequest);
            $medicineRequest->load(['items.medicine', 'resident', 'prescription', 'prescriptions']);

            // Prescription medicines: the staff must check the uploaded prescription before approving.
            $rxNames = $medicineRequest->request_type === 'medicine' ? $medicineRequest->prescriptionMedicines() : collect();
            if ($rxNames->isNotEmpty()) {
                if ($medicineRequest->prescriptions->isEmpty() || $medicineRequest->missingPrescriptionMedicineIds()) {
                    throw ValidationException::withMessages(['prescription' => 'No prescription was uploaded for ' . $rxNames->implode(', ') . '. Reject the request or ask the resident to upload one.']);
                }
                if (! $request->boolean('prescription_verified')) {
                    throw ValidationException::withMessages(['prescription_verified' => 'Please check the prescription and confirm it is valid before approving.']);
                }
                if ($medicineRequest->prescriptions->count() > 1 || $request->has('verified_prescription_ids')) {
                    $checked = $request->validate(['verified_prescription_ids' => 'required|array|list|max:5', 'verified_prescription_ids.*' => 'required|integer|distinct']);
                    $ids = array_map('intval', $checked['verified_prescription_ids']);
                    $expected = $medicineRequest->prescriptions->pluck('prescription_id')->map(fn ($id) => (int) $id)->all();
                    if (array_diff($expected, $ids) || array_diff($ids, $expected)) {
                        throw ValidationException::withMessages(['verified_prescription_ids' => 'Check every uploaded prescription before approving this request.']);
                    }
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
                    . "Bring your Resident ID/QR {$medicineRequest->resident->qr_code} and a valid ID or Barangay ID. "
                    . ($rxNames->isNotEmpty() ? 'Bring all original prescriptions when claiming your medicines. ' : '')
                    . 'After the deadline, the medicine will no longer be given.';
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
            return [$medicineRequest, $message];
        });
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

    /**
     * Medicine requests: each medicine with a quantity of 1 to botika.max_request_quantity (10).
     * Restock requests: only the medicines (no quantity); a quantity sent with them is not saved.
     */
    private function validateItems(Request $request, bool $withType): array
    {
        $restock = $withType && $request->input('request_type') === 'restock';
        $rules = [
            'items' => 'required|array|list|min:1|max:5',
            'items.*' => 'required|array',
            'items.*.medicine_id' => 'bail|required|integer|distinct|exists:medicines,medicine_id',
        ];
        if (! $restock) {
            $rules['items.*.quantity'] = 'required|integer|min:1|max:' . config('botika.max_request_quantity');
        }
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
            'items.max' => 'One request can have at most :max medicines.',
        ]);
        $data['items'] = array_map(fn ($i) => [
            'medicine_id' => (int) $i['medicine_id'],
            'quantity' => $restock ? null : (int) $i['quantity'],
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
     * restock requests are only for medicines that are out of stock (no free stock left).
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
            if ($type === 'restock' && $available > 0) {
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
        $medicineRequest->load(['items.medicine', 'prescriptions']);
        abort_unless($medicineRequest->request_type === 'medicine', 422, 'Restock requests do not need prescriptions.');
        if (! $request->has('prescriptions') && $medicineRequest->prescriptions->count() > 1) {
            throw ValidationException::withMessages(['prescriptions' => 'Manage all prescriptions together so existing files are not accidentally removed.']);
        }
        $rxIds = $medicineRequest->items->filter(fn ($item) => $item->medicine?->requires_prescription)->pluck('medicine_id')->map(fn ($id) => (int) $id)->all();
        $files = $this->validatePrescriptions($request, $rxIds, $medicineRequest);
        DB::transaction(function () use ($request, $medicineRequest, $files) {
            $medicineRequest = MedicineRequest::whereKey($medicineRequest->request_id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwnPending($request, $medicineRequest);
            $medicineRequest->prescriptions()->delete();
            $medicineRequest->prescriptions()->createMany($files);
            $medicineRequest->update(['prescription_verified_at' => null, 'prescription_verified_by' => null]);
        });

        return $medicineRequest->fresh(self::DETAILS);
    }

    /** The prescription file (for the resident who uploaded it, and for staff/admin). */
    public function showPrescription(Request $request, MedicineRequest $medicineRequest, ?Prescription $prescription = null)
    {
        $user = $request->user();
        if ($user->role === User::ROLE_RESIDENT) {
            abort_unless((int) $medicineRequest->resident_id === (int) $user->resident?->resident_id, 404);
        }
        $p = $prescription ?? $medicineRequest->prescription;
        abort_unless($p, 404, 'No prescription was uploaded for this request.');
        abort_unless((int) $p->request_id === (int) $medicineRequest->request_id, 404);

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
    private function validatePrescriptionInput(mixed $input, string $forWhat): array
    {
        if (! is_array($input) || empty($input['data'])) {
            throw ValidationException::withMessages([
                'prescription' => "A doctor's prescription is required for {$forWhat}. Please upload a clear photo or PDF of it.",
            ]);
        }

        // "data:image/jpeg;base64,...." — check the type, then that the rest is plain base64 text.
        if (! is_string($input['data']) || ! is_string($input['name'] ?? null)
            || trim($input['name']) === '' || mb_strlen($input['name']) > 200) {
            throw ValidationException::withMessages(['prescription' => 'Choose a prescription file with a filename of 1–200 characters.']);
        }
        $data = $input['data'];
        if (strlen($data) > (int) ceil(Prescription::MAX_BYTES / 3) * 4 + 100) {
            throw ValidationException::withMessages(['prescription' => 'The prescription file is too large (4 MB at most).']);
        }
        $comma = strpos($data, ',');
        $isFile = $comma !== false && $comma < 100
            && preg_match('#^data:([a-z/+.-]+);base64$#', substr($data, 0, $comma), $m)
            && in_array($m[1], Prescription::MIME_TYPES, true);
        $base64 = $isFile ? substr($data, $comma + 1) : '';
        if (! $isFile || $base64 === '' || strspn($base64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($base64)) {
            throw ValidationException::withMessages(['prescription' => 'The prescription must be a JPG, PNG or PDF file.']);
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '' || base64_encode($bytes) !== $base64) {
            throw ValidationException::withMessages(['prescription' => 'The prescription file is empty or corrupted. Upload it again.']);
        }
        $size = strlen($bytes);
        if ($size > Prescription::MAX_BYTES) {
            throw ValidationException::withMessages(['prescription' => 'The prescription file is too large (4 MB at most).']);
        }
        $actualMime = $m[1] === 'application/pdf'
            ? (str_starts_with($bytes, '%PDF-') ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : false)
            : ((@getimagesizefromstring($bytes))['mime'] ?? false);
        if ($actualMime !== $m[1]) {
            throw ValidationException::withMessages(['prescription' => 'The uploaded file does not match its type. Choose a valid JPG, PNG, WebP or PDF.']);
        }

        return [
            'file_name' => mb_substr(trim((string) ($input['name'] ?? '')) ?: 'prescription', 0, 200),
            'mime_type' => $m[1],
            'size' => $size,
            'file_data' => $base64,
            'uploaded_at' => now(),
        ];
    }

    /** Validate coverage and all files before creating or replacing any stored prescription. */
    private function validatePrescriptions(Request $request, array $rxIds, ?MedicineRequest $existing = null): array
    {
        if (! $rxIds) {
            if ($request->filled('prescriptions')) throw ValidationException::withMessages(['prescriptions' => 'None of these medicines require a prescription.']);
            return [];
        }
        if (! $request->has('prescriptions')) {
            return [[...$this->validatePrescriptionInput($request->input('prescription'), 'these medicines'), 'medicine_ids' => $rxIds]];
        }
        $data = $request->validate([
            'prescriptions' => 'required|array|list|min:1|max:' . Prescription::MAX_FILES,
            'prescriptions.*' => 'required|array',
            'prescriptions.*.medicine_ids' => 'required|array|list|min:1|max:5',
            'prescriptions.*.medicine_ids.*' => ['required', 'integer', Rule::in($rxIds)],
            'prescriptions.*.existing_id' => 'nullable|integer',
        ], ['prescriptions.*.medicine_ids.required' => 'Select the medicines covered by each prescription.',
            'prescriptions.*.medicine_ids.*.in' => 'A prescription can only cover prescription medicines in this request.']);
        // Reject oversized batches before allocating decoded copies of every file.
        $estimatedTotal = 0;
        foreach ($data['prescriptions'] as $index => $row) {
            if (isset($row['existing_id'])) {
                $estimatedTotal += $existing?->prescriptions->firstWhere('prescription_id', (int) $row['existing_id'])?->size ?? 0;
            } else {
                $raw = $request->input("prescriptions.$index.data");
                if (is_string($raw) && ($comma = strpos($raw, ',')) !== false && $comma < 100) {
                    $estimatedTotal += max(0, (int) floor((strlen($raw) - $comma - 1) * 3 / 4)
                        - (str_ends_with($raw, '==') ? 2 : (str_ends_with($raw, '=') ? 1 : 0)));
                }
            }
        }
        if ($estimatedTotal > Prescription::MAX_TOTAL_BYTES) throw ValidationException::withMessages(['prescriptions' => 'The combined prescription files must be 8 MB or smaller.']);
        $files = []; $covered = []; $hashes = []; $total = 0;
        foreach ($data['prescriptions'] as $index => $row) {
            if (isset($row['existing_id'])) {
                $old = $existing?->prescriptions->firstWhere('prescription_id', (int) $row['existing_id']);
                if (! $old) throw ValidationException::withMessages(["prescriptions.$index.existing_id" => 'This prescription does not belong to this request. Upload the file again.']);
                $file = $old->only(['file_name', 'mime_type', 'size', 'file_data', 'uploaded_at']);
            } else {
                try { $file = $this->validatePrescriptionInput($request->input("prescriptions.$index"), 'the selected medicines'); }
                catch (ValidationException $e) { throw ValidationException::withMessages(["prescriptions.$index.file" => $e->validator->errors()->first()]); }
            }
            $ids = array_map('intval', $row['medicine_ids']);
            if (count($ids) !== count(array_unique($ids))) throw ValidationException::withMessages(["prescriptions.$index.medicine_ids" => 'Select each medicine only once per prescription.']);
            $hash = hash('sha256', $file['file_data']);
            if (in_array($hash, $hashes, true)) throw ValidationException::withMessages(["prescriptions.$index.file" => 'This file is already attached. Select all medicines it covers instead of uploading it twice.']);
            $hashes[] = $hash; $total += $file['size']; $covered = array_merge($covered, $ids);
            $files[] = [...$file, 'medicine_ids' => $ids];
        }
        if ($total > Prescription::MAX_TOTAL_BYTES) throw ValidationException::withMessages(['prescriptions' => 'The combined prescription files must be 8 MB or smaller.']);
        if (array_diff($rxIds, $covered)) throw ValidationException::withMessages(['prescriptions' => 'Every prescription medicine must be linked to an uploaded prescription.']);
        return $files;
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
