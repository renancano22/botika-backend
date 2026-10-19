<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup: removes the requests that were rejected before this date, with their
 * "request rejected" notifications. Their medicine items and prescription copies are removed
 * with them (cascade). Rejected requests never took stock, so the inventory is not affected.
 * Requests rejected after this migration ran are kept as usual.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('requests')->where('status', 'rejected')->pluck('request_id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids) {
            DB::table('notifications')->whereIn('request_id', $ids)->delete();
            DB::table('requests')->whereIn('request_id', $ids)->delete();
        });
    }

    public function down(): void
    {
        // Deleted requests cannot be restored.
    }
};
