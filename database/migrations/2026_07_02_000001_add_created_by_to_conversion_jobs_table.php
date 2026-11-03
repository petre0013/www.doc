<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who started the conversion. Queue workers run unauthenticated, so the
     * importing user must ride the job row — DocxImporter previously fell back
     * to a hardcoded user id 1 for asset attribution (wrong author, and an FK
     * violation if that user was ever deleted).
     */
    public function up(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
        });
    }
};
