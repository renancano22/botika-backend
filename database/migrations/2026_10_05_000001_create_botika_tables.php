<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remaining entities of the ERD (Fig. 4.6).
 *
 * Columns added beyond the ERD (needed by the functional requirements):
 *  - residents.user_id        : links a resident profile to its login account
 *  - medicines.reorder_level  : "alerts ... when stock levels fall below the reorder threshold"
 *  - requests.request_type    : medicine request vs. restock request (Activity Diagram, Fig. 4.5)
 *  - requests.remarks         : reason shown to the resident on approval/rejection
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id('resident_id');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('name');
            $table->string('address');
            $table->string('contact_no', 20);
            $table->string('qr_code')->unique(); // Patient ID encoded in the resident's QR code
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id('medicine_id');
            $table->string('medicine_name');
            $table->string('category');
            $table->string('unit');
            $table->text('description')->nullable();
            $table->unsignedInteger('reorder_level')->default(20);
            $table->timestamp('created_at')->useCurrent();
        });

        // One row per stock-in batch, so each batch keeps its own expiration date.
        Schema::create('inventory', function (Blueprint $table) {
            $table->id('inventory_id');
            $table->foreignId('medicine_id')->constrained('medicines', 'medicine_id')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->date('expiration_date');
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->foreignId('resident_id')->constrained('residents', 'resident_id')->cascadeOnDelete();
            $table->enum('request_type', ['medicine', 'restock'])->default('medicine');
            $table->timestamp('request_date')->useCurrent();
            // medicine: pending -> approved -> dispensed | rejected | cancelled
            // restock : pending -> approved -> fulfilled | rejected | cancelled
            $table->enum('status', ['pending', 'approved', 'rejected', 'dispensed', 'fulfilled', 'cancelled'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('remarks')->nullable();
        });

        Schema::create('request_items', function (Blueprint $table) {
            $table->id('request_item_id');
            $table->foreignId('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines', 'medicine_id')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('dispensing', function (Blueprint $table) {
            $table->id('dispensing_id');
            $table->foreignId('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->foreignId('dispensed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('dispensed_at')->useCurrent();
        });

        Schema::create('dispensing_items', function (Blueprint $table) {
            $table->id('dispensing_item_id');
            $table->foreignId('dispensing_id')->constrained('dispensing', 'dispensing_id')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines', 'medicine_id')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->foreignId('resident_id')->constrained('residents', 'resident_id')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests', 'request_id')->nullOnDelete();
            $table->text('message');
            $table->string('channel')->default('sms');
            $table->enum('status', ['sent', 'failed', 'logged'])->default('logged');
            $table->timestamp('sent_at')->useCurrent();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->foreignId('generated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('report_type');
            $table->string('date_range');
            $table->timestamp('generated_at')->useCurrent();
        });

        Schema::create('forecast', function (Blueprint $table) {
            $table->id('forecast_id');
            $table->foreignId('medicine_id')->constrained('medicines', 'medicine_id')->cascadeOnDelete();
            $table->date('forecast_date'); // first day of the month being forecast
            $table->decimal('predicted_demand', 10, 2);
            $table->string('method'); // moving_average | linear_regression | exponential_smoothing
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['medicine_id', 'forecast_date', 'method']);
        });
    }

    public function down(): void
    {
        foreach (['forecast', 'reports', 'notifications', 'dispensing_items', 'dispensing',
                  'request_items', 'requests', 'inventory', 'medicines', 'residents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
