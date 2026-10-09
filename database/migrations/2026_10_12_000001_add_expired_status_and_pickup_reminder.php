<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - requests.status gets "expired": an approved request whose pickup deadline passed without
 *   collection (cancelled_at holds the date it expired).
 * - requests.reminded_at: when the "pickup deadline" reminder was sent (sent only once).
 * Requests that were cancelled automatically before this update become "expired".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'dispensed', 'fulfilled', 'cancelled', 'expired'])
                ->default('pending')->change();
            $table->timestamp('reminded_at')->nullable()->after('fulfilled_at');
        });

        DB::table('requests')->where('status', 'cancelled')->whereNotNull('cancelled_at')->whereNull('cancelled_by')
            ->update(['status' => 'expired']);
        DB::table('notifications')->where('type', 'cancelled')->where('message', 'like', '%not claimed%')
            ->update(['type' => 'expired']);
    }

    public function down(): void
    {
        DB::table('requests')->where('status', 'expired')->update(['status' => 'cancelled']);

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
            $table->enum('status', ['pending', 'approved', 'rejected', 'dispensed', 'fulfilled', 'cancelled'])
                ->default('pending')->change();
        });
    }
};
