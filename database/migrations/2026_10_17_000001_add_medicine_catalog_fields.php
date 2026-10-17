<?php

use App\Support\MedicineCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medicine inventory fields for the Botika ng Bayan medicine list:
 *  - medicines: generic name, strength, dosage form, date last updated, and stock_verified_at
 *    (set when staff record stock or confirm there is none; until then the stock is "not recorded");
 *  - inventory batches: batch / lot number.
 * Then the Botika's medicine list is added (records only, no stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('generic_name')->nullable()->after('medicine_name');
            $table->string('strength', 50)->nullable()->after('generic_name');
            $table->string('dosage_form', 50)->nullable()->after('strength');
            $table->timestamp('stock_verified_at')->nullable()->after('reorder_level');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
        Schema::table('inventory', function (Blueprint $table) {
            $table->string('batch_number', 60)->nullable()->after('medicine_id');
        });

        // Existing medicines: the name so far is the generic name; tablets and capsules get their dosage form.
        DB::table('medicines')->whereNull('generic_name')->update(['generic_name' => DB::raw('medicine_name'), 'updated_at' => DB::raw('created_at')]);
        DB::table('medicines')->where('unit', 'tablet')->whereNull('dosage_form')->update(['dosage_form' => 'Tablet']);
        DB::table('medicines')->where('unit', 'capsule')->whereNull('dosage_form')->update(['dosage_form' => 'Capsule']);
        // Medicines whose stock was already recorded (a batch or a stock transaction exists).
        DB::table('medicines')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->from('inventory')->whereColumn('inventory.medicine_id', 'medicines.medicine_id'))
                ->orWhereExists(fn ($s) => $s->from('stock_transactions')->whereColumn('stock_transactions.medicine_id', 'medicines.medicine_id')))
            ->update(['stock_verified_at' => now()]);

        // The Botika ng Bayan medicine list (tests start from an empty list and import it themselves).
        if (! app()->runningUnitTests()) {
            MedicineCatalog::import();
        }
    }

    public function down(): void
    {
        Schema::table('inventory', fn (Blueprint $table) => $table->dropColumn('batch_number'));
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['generic_name', 'strength', 'dosage_form', 'stock_verified_at', 'updated_at']));
    }
};
