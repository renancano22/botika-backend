<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Batch / lot numbers are not used: removes the column if an earlier version of the migration added it. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory', 'batch_number')) {
            Schema::table('inventory', fn (Blueprint $table) => $table->dropColumn('batch_number'));
        }
    }

    public function down(): void
    {
        // nothing to restore
    }
};
