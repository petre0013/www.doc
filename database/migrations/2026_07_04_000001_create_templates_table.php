<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page templates: reusable starting points for new pages. A dedicated table —
 * NOT a flag on documents — so templates stay out of every listing path (tree,
 * search, backlinks, tags, trash) by construction: no versioning, no wiki-link
 * parsing, no search vector. Hard-deleted (audited); no soft-delete machinery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('content')->nullable();       // TipTap JSON, copied verbatim on instantiate
            // Scrub-to-NULL on user delete, same as documents' author columns.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
