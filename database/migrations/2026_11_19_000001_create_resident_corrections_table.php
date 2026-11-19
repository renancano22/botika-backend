<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resident_corrections', function (Blueprint $table) {
            $table->id('correction_id');
            $table->foreignId('resident_id')->nullable()->constrained('residents', 'resident_id')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('resident_code', 50);
            $table->string('actor_name');
            $table->string('verification_method', 30);
            $table->text('reason');
            $table->json('changes');
            $table->timestamp('verified_at');
            $table->timestamp('created_at');
            $table->index(['resident_id', 'correction_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('resident_corrections'); }
};
