<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User Permissions and Restrictions: which features each role (Pharmacy Staff, Resident) may use.
 * Only the administrator's choices are stored; a permission without a row uses its default
 * from config/permissions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id('role_permission_id');
            $table->string('role', 20);
            $table->string('permission', 60);
            $table->boolean('allowed');
            $table->foreignId('updated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['role', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
