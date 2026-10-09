<?php

namespace App\Http\Middleware;

use App\Services\InventoryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cancels approved requests that were not claimed in time. The free online server has no
 * scheduled jobs, so this check runs together with normal API calls, at most once every 10 minutes.
 */
class ExpireUnclaimedRequests
{
    public function __construct(private InventoryService $inventory) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::add('botika:expire-unclaimed-check', true, now()->addMinutes(10))) {
            try {
                $this->inventory->expireUnclaimedRequests();
            } catch (Throwable $e) {
                Log::warning('Unclaimed request check failed: ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}
