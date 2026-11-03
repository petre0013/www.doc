<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track an in-progress restore on the backup row so the admin UI can show a
 * progress modal and toast the outcome (the restore job is async on the queue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('restore_status')->nullable(); // null | restoring | restored | failed
            $table->text('restore_error')->nullable();
            $table->timestamp('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['restore_status', 'restore_error', 'restored_at']);
        });
    }
};
