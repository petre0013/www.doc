<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained('documents')->cascadeOnDelete();
            // Nullable: a [[Wiki-link]] may point at a page that doesn't exist yet.
            $table->foreignId('target_document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('target_title');
            $table->timestamps();

            $table->index('source_document_id');
            $table->index('target_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
