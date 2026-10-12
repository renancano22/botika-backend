<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription medicines:
 * - medicines.requires_prescription: the medicine can only be requested/dispensed with a doctor's prescription.
 * - prescriptions: the photo/PDF of the prescription a resident uploads with a request (kept in the
 *   database so it is not lost when the online server restarts).
 * - requests.prescription_verified_at/_by: the pharmacy staff checked the prescription before approving.
 * - dispensing.prescription_checked: the staff saw the original prescription when releasing the medicine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->boolean('requires_prescription')->default(false)->after('description');
        });
        // Medicines already described as "Requires prescription" are marked automatically.
        DB::table('medicines')->where('description', 'like', '%requires prescription%')->update(['requires_prescription' => true]);

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id('prescription_id');
            $table->foreignId('request_id')->unique()->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('mime_type', 50);
            $table->unsignedInteger('size');
            $table->longText('file_data'); // base64
            $table->timestamp('uploaded_at')->useCurrent();
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('prescription_verified_at')->nullable()->after('reviewed_at');
            $table->foreignId('prescription_verified_by')->nullable()->after('prescription_verified_at')
                ->constrained('users', 'user_id')->nullOnDelete();
        });

        Schema::table('dispensing', function (Blueprint $table) {
            $table->boolean('prescription_checked')->default(false)->after('dispensed_at');
        });
    }

    public function down(): void
    {
        Schema::table('dispensing', fn (Blueprint $table) => $table->dropColumn('prescription_checked'));
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_verified_by');
            $table->dropColumn('prescription_verified_at');
        });
        Schema::dropIfExists('prescriptions');
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn('requires_prescription'));
    }
};
