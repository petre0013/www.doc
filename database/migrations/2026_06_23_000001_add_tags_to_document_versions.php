<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A version is a full snapshot of how a page looked at save time. Tags
        // aren't part of the document row (polymorphic pivot), so we capture the
        // tag NAMES here — names survive tag rename/deletion, letting restore
        // recreate them faithfully. Pre-existing versions default to "no tags".
        Schema::table('document_versions', function (Blueprint $table) {
            $table->jsonb('tags')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
