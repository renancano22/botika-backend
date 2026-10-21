<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Server-side pagination for the data tables (rows per page: 5 by default, or 10, 25 or 50).
 *
 * A list is paginated when the request has ?per_page= or ?page=. The response is
 *   { data: [...one page...], meta: { current_page, per_page, total, last_page, from, to }, ...extra }
 * Without those parameters an endpoint keeps its previous response (screens that need the whole list,
 * e.g. the medicine dropdowns), so nothing else changes.
 *
 * Only one page of records is read from the database and sent to the browser. Every list orders by a
 * date and then by its ID, so records with the same date keep a fixed order and none is skipped or
 * repeated between pages. "No." in the tables is the row's position (meta.from + index), never an ID.
 */
class Listing
{
    public const DEFAULT_PER_PAGE = 5;
    public const MAX_PER_PAGE = 100;

    /** Does this request ask for one page (a data table) instead of the whole list? */
    public static function wanted(Request $request): bool
    {
        return $request->filled('per_page') || $request->filled('page');
    }

    public static function perPage(Request $request): int
    {
        $n = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        return $n < 1 ? self::DEFAULT_PER_PAGE : min(self::MAX_PER_PAGE, $n);
    }

    /** ?from= / ?to= (YYYY-MM-DD, both days included). */
    public static function validateDates(Request $request): void
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ], [
            'from.date_format' => 'Choose a valid "From" date.',
            'to.date_format' => 'Choose a valid "To" date.',
            'to.after_or_equal' => 'The "To" date must be on or after the "From" date.',
        ]);
    }

    /** Records whose $column falls within ?from= and ?to= (whole days). */
    public static function dateRange(Builder $query, Request $request, string $column): Builder
    {
        return $query
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate($column, '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate($column, '<=', $d));
    }

    /**
     * The typed search, trimmed. "Request No. 41", "No. 41" and "#41" become "41".
     */
    public static function search(Request $request): string
    {
        $s = trim((string) $request->input('search', ''));
        return mb_substr(preg_replace('/^(request\s*)?(no\.?|#)\s*(?=\d)/i', '', $s), 0, 100);
    }

    /**
     * A number typed as a number ("Request No. 41", "No. 41", "#41"): only that record is searched for,
     * so it does not also match Resident IDs or names that contain those digits. Null for any other search.
     */
    public static function number(Request $request): ?int
    {
        return preg_match('/^\s*(request\s*|batch\s*)?(no\.?|#)\s*(\d{1,9})\s*$/i', (string) $request->input('search', ''), $m) ? (int) $m[3] : null;
    }

    /** "%term%" for LIKE, with % and _ typed by the user matched literally. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /** ?sort= value if it is one of $allowed, otherwise the first one (the default). */
    public static function sort(Request $request, array $allowed): string
    {
        $sort = (string) $request->input('sort', '');
        return in_array($sort, $allowed, true) ? $sort : $allowed[0];
    }

    /**
     * One page of a query. A page number after the last page (e.g. after a filter made the list shorter)
     * shows the last page. With $focusKey and ?focus=<id>, the page that contains that record is shown
     * (e.g. an expiry alert opening its batch).
     * @param callable(Collection): Collection|null $map changes the records of the page before they are sent
     */
    public static function paginate(Builder $query, Request $request, ?callable $map = null, array $extra = [], ?string $focusKey = null): array
    {
        $perPage = self::perPage($request);
        $page = max(1, (int) $request->input('page', 1));

        if ($focusKey && $request->filled('focus')) {
            $index = (clone $query)->pluck($focusKey)->search(fn ($id) => (string) $id === (string) $request->input('focus'));
            if ($index !== false) {
                $page = intdiv($index, $perPage) + 1;
            }
        }

        $paginator = (clone $query)->paginate($perPage, ['*'], 'page', $page);
        if ($paginator->total() > 0 && $page > $paginator->lastPage()) {
            $paginator = (clone $query)->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        $items = collect($paginator->items());
        if ($map) {
            $items = $map($items);
        }

        return ['data' => $items->values(), 'meta' => self::meta($paginator->currentPage(), $perPage, $paginator->total(), $items->count())] + $extra;
    }

    /** One page of a list that had to be built in PHP first (e.g. medicines with their computed stock status). */
    public static function paginateCollection(Collection $all, Request $request, array $extra = []): array
    {
        $perPage = self::perPage($request);
        $total = $all->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($last, max(1, (int) $request->input('page', 1)));
        $items = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return ['data' => $items, 'meta' => self::meta($page, $perPage, $total, $items->count())] + $extra;
    }

    private static function meta(int $page, int $perPage, int $total, int $count): array
    {
        $from = $count ? ($page - 1) * $perPage + 1 : null;
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $from,
            'to' => $from ? $from + $count - 1 : null,
        ];
    }
}
