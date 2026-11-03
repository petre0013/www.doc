<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-version change summary vs the PREVIOUS snapshot (word/block deltas,
 * diagram-touched flag), computed at save time by DocumentObserver via
 * DocumentDiff::summarize. Nullable on purpose: pre-existing rows and each
 * document's first version have no baseline — the history list simply shows
 * no badge for them. No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->jsonb('summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
