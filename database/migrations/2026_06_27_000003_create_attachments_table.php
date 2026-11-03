<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            // Page-scoped: purging a document cascades its attachment rows. Binary
            // files are cleaned up before the purge (see Document::forceDeleteSubtree).
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // Private disk by default — attachments are served through a forced
            // download endpoint, never a public URL.
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('document_id');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
