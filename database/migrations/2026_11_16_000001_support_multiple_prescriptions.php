<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            // Keep a supporting index for the request foreign key before removing the unique index (MySQL).
            $table->index('request_id', 'prescriptions_request_lookup');
            $table->json('medicine_ids')->nullable();
        });
        Schema::table('prescriptions', fn (Blueprint $table) => $table->dropUnique(['request_id']));
        // Existing single-file requests covered all of their prescription medicines. Preserve that association.
        DB::table('prescriptions')->orderBy('prescription_id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $ids = DB::table('request_items')->join('medicines', 'medicines.medicine_id', '=', 'request_items.medicine_id')
                    ->where('request_items.request_id', $row->request_id)->where('medicines.requires_prescription', true)
                    ->pluck('request_items.medicine_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                DB::table('prescriptions')->where('prescription_id', $row->prescription_id)->update(['medicine_ids' => json_encode($ids)]);
            }
        }, 'prescription_id');
    }

    public function down(): void
    {
        if (DB::table('prescriptions')->select('request_id')->groupBy('request_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back multiple prescriptions while requests contain more than one file. No files were deleted.');
        }
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->unique('request_id');
            $table->dropIndex('prescriptions_request_lookup');
            $table->dropColumn('medicine_ids');
        });
    }
};
