<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->jsonb('content')->default('{}');
            $table->text('content_html')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Slugs are unique within a workspace, not globally.
            $table->unique(['workspace_id', 'slug']);
            $table->index('parent_id');
            $table->index('slug');
        });

        // Postgres-specific columns/indexes the schema builder can't express.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents ADD COLUMN search_vector tsvector');
            DB::statement('CREATE INDEX documents_search_vector_idx ON documents USING GIN (search_vector)');
            DB::statement('CREATE INDEX documents_metadata_idx ON documents USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
