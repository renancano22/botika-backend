<?php

namespace App\Services;

use App\Models\DispensingItem;
use App\Models\RequestItem;
use Illuminate\Validation\ValidationException;

/**
 * Monthly limit per resident (config botika.monthly_limit, default 100): the total quantity of
 * medicines one resident can receive in a calendar month, online requests and walk-ins together.
 * When it is used up, the resident has to wait until the 1st of next month.
 *
 * Quantities counted this month:
 *  - claimed   : already dispensed to the resident this month;
 *  - set_aside : approved medicine requests not yet claimed (the medicine is reserved for them);
 *  - pending   : medicine requests still waiting for review.
 */
class MonthlyLimit
{
    public static function limit(): int
    {
        return (int) config('botika.monthly_limit', 100);
    }

    /** @return array{limit: int, claimed: int, set_aside: int, pending: int, remaining: int, month: string, resets_on: string} */
    public function usage(int $residentId, ?int $exceptRequestId = null): array
    {
        $claimed = (int) DispensingItem::query()
            ->join('dispensing', 'dispensing.dispensing_id', '=', 'dispensing_items.dispensing_id')
            ->join('requests', 'requests.request_id', '=', 'dispensing.request_id')
            ->where('requests.resident_id', $residentId)
            ->where('dispensing.dispensed_at', '>=', now()->startOfMonth())
            ->sum('dispensing_items.quantity');

        $open = fn (string $status) => (int) RequestItem::query()
            ->join('requests', 'requests.request_id', '=', 'request_items.request_id')
            ->where('requests.resident_id', $residentId)
            ->where('requests.request_type', 'medicine')
            ->where('requests.status', $status)
            ->when($exceptRequestId, fn ($q, $id) => $q->where('requests.request_id', '!=', $id))
            ->sum('request_items.quantity');

        $setAside = $open('approved');
        $pending = $open('pending');
        $limit = self::limit();

        return [
            'limit' => $limit,
            'claimed' => $claimed,
            'set_aside' => $setAside,
            'pending' => $pending,
            'remaining' => max(0, $limit - $claimed - $setAside - $pending),
            'month' => now()->format('F Y'),
            'resets_on' => now()->startOfMonth()->addMonthNoOverflow()->toDateString(),
        ];
    }

    /**
     * Stops a request, approval or release that would go over the monthly limit.
     *  - request  : resident submits/updates (claimed + set aside + pending + new)
     *  - approve  : staff approves (claimed + other set-aside requests + this one)
     *  - dispense : staff releases an approved request (claimed + this one)
     *  - walk_in  : staff dispenses a walk-in (claimed + set aside + walk-in)
     */
    public function check(int $residentId, int $quantity, string $stage, ?int $exceptRequestId = null): void
    {
        $u = $this->usage($residentId, $exceptRequestId);
        $used = match ($stage) {
            'request' => $u['claimed'] + $u['set_aside'] + $u['pending'],
            'approve', 'walk_in' => $u['claimed'] + $u['set_aside'],
            'dispense' => $u['claimed'],
        };
        $left = max(0, $u['limit'] - $used);
        if ($used + $quantity <= $u['limit']) {
            return;
        }

        $resets = now()->startOfMonth()->addMonthNoOverflow()->format('F j, Y');
        $parts = ["received {$u['claimed']} this month"];
        if (in_array($stage, ['request', 'approve', 'walk_in'], true) && $u['set_aside'] > 0) {
            $parts[] = "{$u['set_aside']} set aside in approved requests";
        }
        if ($stage === 'request' && $u['pending'] > 0) {
            $parts[] = "{$u['pending']} in pending requests";
        }
        $detail = implode(', ', $parts);

        $message = $stage === 'request'
            ? ($left === 0
                ? "You have reached the monthly limit of {$u['limit']} medicines ({$detail}). You can request again on {$resets}."
                : "You can receive up to {$u['limit']} medicines per month ({$detail}), so you can only request {$left} more this month. Your limit resets on {$resets}.")
            : "This resident can receive up to {$u['limit']} medicines per month and has {$detail}. "
                . ($left === 0 ? "No more can be given until {$resets}." : "Only {$left} more can be given this month (this one has {$quantity}).");

        throw ValidationException::withMessages(['monthly_limit' => $message]);
    }
}
