<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder level (the "Low stock" alert) is 100 automatically: new medicines start at 100 and every medicine already
 * in the list is set to 100. It can still be changed per medicine in Medicine List → Edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->default(100)->change();
        });
        DB::table('medicines')->update(['reorder_level' => 100]);
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->default(20)->change();
        });
    }
};
