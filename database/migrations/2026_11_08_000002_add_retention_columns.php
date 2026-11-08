<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence retention: accounts are deactivated rather than deleted, removed
 * attachments stay with the page's history, and backups record when their
 * archive was last verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->string('verify_status')->nullable(); // passed | failed
            $table->text('verify_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('deactivated_at'));
        Schema::table('attachments', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('backups', fn (Blueprint $table) => $table->dropColumn(['verified_at', 'verify_status', 'verify_error']));
    }
};
