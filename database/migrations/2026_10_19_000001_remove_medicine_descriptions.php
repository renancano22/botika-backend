<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Medicine descriptions (e.g. "For bacterial infections. Requires prescription.") are no longer used:
 * the old texts are cleared so they are not shown anywhere. Prescription medicines keep their
 * "Requires Prescription" flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('medicines')->whereNotNull('description')->update(['description' => null]);
    }

    public function down(): void
    {
        // The cleared texts cannot be restored.
    }
};
