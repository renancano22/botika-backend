<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each medicine came from: 'request' (asked for in the online request) or 'walk_in' (asked for in person at the
 * Botika ng Bayan). When a resident claims an approved request and asks for more medicine in the same visit, the
 * extra medicines are added to that request as walk-in items and everything is given in ONE dispensing record,
 * each medicine marked Request or Walk-in. Walk-in releases without a request are walk-in items too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['request_items', 'dispensing_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('source', 10)->default('request');
            });
        }

        // Earlier walk-in releases (saved as requests with the remark "Walk-in").
        $walkIns = DB::table('requests')->where('remarks', 'Walk-in')->pluck('request_id');
        foreach ($walkIns->chunk(500) as $ids) {
            DB::table('request_items')->whereIn('request_id', $ids)->update(['source' => 'walk_in']);
            DB::table('dispensing_items')
                ->whereIn('dispensing_id', DB::table('dispensing')->whereIn('request_id', $ids)->select('dispensing_id'))
                ->update(['source' => 'walk_in']);
        }
    }

    public function down(): void
    {
        foreach (['request_items', 'dispensing_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('source');
            });
        }
    }
};
