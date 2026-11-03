<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user navigation state (starred pages, recently viewed). A pivot — NOT
 * `documents.metadata` — because writing per-user state onto the shared
 * document row would bump updated_at and contend with the optimistic-lock
 * counter's environment on every page view. Cascade FKs on both sides mean a
 * user delete or a document purge cleans these rows up for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starred_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();

            $table->unique(['user_id', 'document_id']);
            // The two list queries: a user's stars, a user's recents.
            $table->index(['user_id', 'starred_at']);
            $table->index(['user_id', 'last_viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_user');
    }
};
