<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restock requests have no quantity (the resident only asks for the medicine to be restocked), so the quantity of
 * a request item may be empty. Medicine requests still always have a quantity (checked when they are submitted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable()->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('request_items')->whereNull('quantity')->update(['quantity' => 1]);
        Schema::table('request_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable(false)->default(1)->change();
        });
    }
};
