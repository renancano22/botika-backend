<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-in and stock-out transactions (Scope, Section 1.4: "recording stock-in and
 * stock-out transactions"). Every change to a batch quantity is logged here with the
 * person who did it, so the inventory can be audited.
 *   stock_in  - new batch received
 *   stock_out - items removed by staff (expired, damaged, lost, ...) with a reason
 *   dispensed - items given to a resident (linked to the dispensing record)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id('transaction_id');
            $table->foreignId('medicine_id')->constrained('medicines', 'medicine_id')->cascadeOnDelete();
            $table->foreignId('inventory_id')->nullable()->constrained('inventory', 'inventory_id')->nullOnDelete();
            $table->enum('type', ['stock_in', 'stock_out', 'dispensed']);
            $table->unsignedInteger('quantity');
            $table->string('reason')->nullable();
            $table->foreignId('dispensing_id')->nullable()->constrained('dispensing', 'dispensing_id')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
