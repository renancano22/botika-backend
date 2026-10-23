<?php

use App\Support\MedicineIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate medicine detection: identity_key = normalized active ingredient | strength | dosage form
 * (e.g. "amoxicillin|500mg|capsule"), with a UNIQUE index so the same medicine cannot be saved twice, even when
 * two people save it at the same moment. Filled in for the existing medicines; if two existing records already
 * have the same key, only the oldest one gets it (nothing is deleted or merged, and no stock changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('identity_key', 191)->nullable()->after('dosage_form');
        });

        $taken = [];
        foreach (DB::table('medicines')->orderBy('medicine_id')->get(['medicine_id', 'medicine_name', 'generic_name', 'strength', 'dosage_form']) as $m) {
            $key = MedicineIdentity::key($m->generic_name, $m->strength, $m->dosage_form, $m->medicine_name);
            if (isset($taken[$key])) continue; // an older record already has this key
            $taken[$key] = true;
            DB::table('medicines')->where('medicine_id', $m->medicine_id)->update(['identity_key' => $key]);
        }

        Schema::table('medicines', function (Blueprint $table) {
            $table->unique('identity_key');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropUnique(['identity_key']);
            $table->dropColumn('identity_key');
        });
    }
};
