<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the admin started this backup from, captured in the same request
     * that set `created_by_id`. The queue job that logs the completion has no
     * request of its own, so the IP has to ride the row with the actor —
     * mirrors `conversion_jobs.ip`. Scheduled runs leave both null.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('created_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
