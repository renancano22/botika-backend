<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Medicine;
use App\Models\StockTransaction;
use App\Services\InventoryService;
use App\Support\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Real-time tracking of medicine stocks and expiration dates (Objective 3.1). */
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Stock batches (batches with stock left; ?expiry=empty lists the used-up ones, which are kept as history).
     * Data table (?per_page=): one page, with ?search= (medicine), ?expiry=expired|near_expiry|ok|empty,
     * ?sort=expiry (earliest expiration first) | expiry_desc | newest, and ?focus=<batch id> to open the
     * page that contains that batch (expiry alerts).
     */
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $warnUntil = InventoryService::nearExpiryCutoff();
        $withStatus = fn ($batches) => $batches->map(function (Inventory $b) use ($today, $warnUntil) {
            $exp = $b->expiration_date->toDateString();
            $b->setAttribute('expiry_status', $exp <= $today ? 'expired' : ($exp <= $warnUntil ? 'near_expiry' : 'ok'));
            return $b;
        });
        $expiry = (string) $request->input('expiry', '');
        $search = Listing::search($request);

        $query = Inventory::with('medicine:medicine_id,medicine_name,generic_name,strength,dosage_form,category,unit')
            ->when($request->medicine_id, fn ($q, $id) => $q->where('medicine_id', $id))
            ->when($expiry === 'empty', fn ($q) => $q->where('quantity', '<=', 0),
                fn ($q) => $q->when(! $request->boolean('include_empty'), fn ($w) => $w->where('quantity', '>', 0)))
            ->when($expiry === 'expired', fn ($q) => $q->whereDate('expiration_date', '<=', $today))
            ->when($expiry === 'near_expiry', fn ($q) => $q->whereDate('expiration_date', '>', $today)->whereDate('expiration_date', '<=', $warnUntil))
            ->when($expiry === 'ok', fn ($q) => $q->whereDate('expiration_date', '>', $warnUntil))
            ->when(Listing::number($request) !== null, fn ($q) => $q->where('inventory_id', Listing::number($request)))
            ->when($search !== '' && Listing::number($request) === null, fn ($q) => $q->where(fn ($w) => $w
                ->when(ctype_digit($search), fn ($x) => $x->orWhere('inventory_id', (int) $search))
                ->orWhereHas('medicine', fn ($m) => $m->where('medicine_name', 'like', Listing::like($search))->orWhere('generic_name', 'like', Listing::like($search)))));

        if (Listing::wanted($request)) {
            match (Listing::sort($request, ['expiry', 'expiry_desc', 'newest'])) {
                'expiry' => $query->orderBy('expiration_date')->orderBy('inventory_id'),
                'expiry_desc' => $query->orderByDesc('expiration_date')->orderByDesc('inventory_id'),
                'newest' => $query->orderByDesc('inventory_id'),
            };
            return Listing::paginate($query, $request, $withStatus, [], 'inventory_id');
        }

        return $withStatus($query->orderBy('expiration_date')->orderBy('inventory_id')->get());
    }

    public function stockIn(Request $request)
    {
        $data = $request->validate([
            'medicine_id' => 'required|exists:medicines,medicine_id',
            'quantity' => 'required|integer|min:1|max:100000',
            'expiration_date' => ['required', 'date', 'after:today', 'before_or_equal:' . now()->addYears(10)->toDateString()],
        ], [
            'medicine_id.required' => 'Choose a medicine.',
            'medicine_id.exists' => 'This medicine is not in the medicine list.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'A single stock-in can be at most 100,000.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'expiration_date.after' => 'The expiration date must be after today. Expired medicine cannot be added to the stock.',
            'expiration_date.before_or_equal' => 'Check the expiration date: it cannot be more than 10 years from today.',
        ]);

        $batch = DB::transaction(function () use ($data, $request) {
            // One stock-in at a time per medicine, so a double click / resent request cannot slip in twice.
            Medicine::whereKey($data['medicine_id'])->lockForUpdate()->first();

            // The same stock-in (same medicine, quantity and expiration date, same staff) saved seconds ago
            // is a duplicate submission, not a second delivery.
            $duplicate = StockTransaction::where('type', 'stock_in')
                ->where('medicine_id', $data['medicine_id'])
                ->where('quantity', $data['quantity'])
                ->where('performed_by', $request->user()->user_id)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->whereHas('batch', fn ($q) => $q->whereDate('expiration_date', $data['expiration_date']))
                ->latest('transaction_id')
                ->first();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'medicine_id' => "This stock-in was already saved a moment ago (Batch #{$duplicate->inventory_id}), so it was not recorded twice.",
                ]);
            }

            return $this->inventory->stockIn(
                (int) $data['medicine_id'], (int) $data['quantity'], $data['expiration_date'], $request->user()->user_id
            );
        });

        return response()->json($batch->load('medicine'), 201);
    }

    public function stockOut(Request $request, Inventory $inventory)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'reason.required' => 'Choose or type the reason for the stock-out.',
            'reason.min' => 'Please describe the reason (at least 3 characters).',
        ]);

        return DB::transaction(fn () => $this->inventory
            ->stockOut($inventory, (int) $data['quantity'], $data['reason'], $request->user()->user_id)
            ->load('medicine'));
    }

    /**
     * Stock-in / stock-out / dispensed history (audit trail of every quantity change), newest first.
     * Data table (?per_page=): one page, with ?type=, ?search= (medicine or batch no.), ?from= / ?to= and ?sort=newest|oldest.
     */
    public function transactions(Request $request)
    {
        $request->validate([
            'type' => 'nullable|in:stock_in,stock_out,dispensed',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'medicine_id' => 'nullable|integer',
        ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);
        $search = Listing::search($request);
        $withReason = fn ($rows) => $rows->each(function (StockTransaction $t) {
            $t->setAttribute('reason_text', $t->reasonText());
            $t->unsetRelation('dispensing'); // only the text is needed (keeps the list light)
        });

        $query = StockTransaction::with(['medicine:medicine_id,medicine_name,unit', 'batch:inventory_id,expiration_date', 'performer:user_id,name', ...StockTransaction::REASON_RELATIONS])
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->medicine_id, fn ($q, $id) => $q->where('medicine_id', $id))
            ->when(Listing::number($request) !== null, fn ($q) => $q->where('inventory_id', Listing::number($request)))
            ->when($search !== '' && Listing::number($request) === null, fn ($q) => $q->where(fn ($w) => $w
                ->when(ctype_digit($search), fn ($x) => $x->orWhere('inventory_id', (int) $search))
                ->orWhereHas('medicine', fn ($m) => $m->where('medicine_name', 'like', Listing::like($search)))));
        Listing::dateRange($query, $request, 'created_at');

        if (Listing::wanted($request)) {
            $dir = Listing::sort($request, ['newest', 'oldest']) === 'oldest' ? 'asc' : 'desc';
            return Listing::paginate($query->orderBy('created_at', $dir)->orderBy('transaction_id', $dir), $request, $withReason);
        }

        return $withReason($query->orderByDesc('created_at')->orderByDesc('transaction_id')->limit(500)->get());
    }

    public function alerts()
    {
        return $this->inventory->alerts();
    }
}
