<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail: who did what, when. Rows are never updated or
 * deleted through the app (the model throws) — only `audit:prune` removes rows
 * past the retention window. Complements the NIS2 backup story.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // Who. Nullable: system actions (scheduled backups) and events that
            // must outlive their user (FK scrubs to NULL, same as backups).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');                    // e.g. document.updated
            // What. A loose morph — no FK, so events survive purges of their
            // subject. Type stores the model's morph alias.
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            // Plain column, no FK: keeps events filterable by workspace even
            // after the workspace itself is purged.
            $table->unsignedBigInteger('workspace_id')->nullable();
            // Human-readable snapshot at event time (titles, old/new values, ip).
            $table->jsonb('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index('event');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('workspace_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
