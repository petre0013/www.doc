<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the import was started from. The queue worker has no request, so an
     * event it writes would otherwise lose the IP that every other user action
     * records — the same reason `created_by_id` rides this row for the actor.
     * 45 chars covers IPv6 (and IPv4-mapped IPv6).
     */
    public function up(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('created_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
