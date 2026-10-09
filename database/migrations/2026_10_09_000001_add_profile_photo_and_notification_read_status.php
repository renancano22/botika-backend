<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - residents.photo: the resident's profile picture (a small JPEG, saved in the database so it
 *   is not lost when the online server restarts).
 * - notifications.read_at: when the resident opened / marked the notification as read
 *   (null = unread).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->mediumText('photo')->nullable()->after('qr_code');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });

        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
