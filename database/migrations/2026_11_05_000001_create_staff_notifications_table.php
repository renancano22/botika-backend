<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications for the administrator and pharmacy staff (in the app only, no SMS): a new medicine request to
 * review, a new restock request for the administrator ("the System records the stock request and notifies the
 * Administrator"), and a request cancelled by the resident. Separate from the residents' notifications (SMS log)
 * and from the expiry alerts (stock problems).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id('staff_notification_id');
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('type', 30);                     // new_request, new_restock, request_cancelled
            $table->string('title', 120);
            $table->string('message', 500);
            $table->foreignId('request_id')->nullable()->constrained('requests', 'request_id')->nullOnDelete();
            $table->string('link', 200)->nullable();         // the page that opens when the notification is tapped
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
