<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residents without a cellphone can be registered by the staff at the Botika ng Bayan,
 * so the mobile number becomes optional (it stays unique when given; it is still required
 * when residents register an account themselves).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->string('contact_no', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->string('contact_no', 20)->nullable(false)->change();
        });
    }
};
