<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app backup notices: when email reports are off (or the report email fails),
 * the admin is told via a dismissable banner instead. `report_emailed` records
 * whether the report actually went out; `acknowledged_at` clears the banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('report_emailed')->default(false);
            $table->text('report_error')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
        });

        // Existing backups are history — acknowledge them so the new banner only
        // surfaces runs that happen from here on.
        DB::table('backups')->update(['acknowledged_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['report_emailed', 'report_error', 'acknowledged_at']);
        });
    }
};
