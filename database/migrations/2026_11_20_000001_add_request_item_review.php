<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            // Null preserves the parent request's decision for existing records and walk-ins.
            $table->string('review_status', 16)->nullable();
            $table->string('rejection_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_items', fn (Blueprint $table) => $table->dropColumn(['review_status', 'rejection_reason']));
    }
};
