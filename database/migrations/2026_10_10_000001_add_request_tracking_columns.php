<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dates shown in the request status tracker:
 * - cancelled_at / cancelled_by: when and by whom a request was cancelled
 *   (cancelled_by = null means it was cancelled automatically because it was not claimed in time).
 * - fulfilled_at: when a restock request's medicine became available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('remarks');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'fulfilled_at']);
        });
    }
};
