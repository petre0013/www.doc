<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optimistic-locking counter: bumped by DocumentObserver only when content
        // or title actually change (never on move/reorder). The editor sends the
        // value it loaded as `base_version`; a mismatch on save means someone else
        // edited the page in the meantime, so we surface a conflict instead of a
        // silent last-write-wins overwrite. Existing rows start at 1.
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
