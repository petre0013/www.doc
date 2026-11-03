<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The uploaded file's own name. `result_path` only holds the randomised
     * storage path, so the name the user recognises would otherwise be lost
     * between the upload request and the queue job that audits the import.
     */
    public function up(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->string('source_name')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->dropColumn('source_name');
        });
    }
};
