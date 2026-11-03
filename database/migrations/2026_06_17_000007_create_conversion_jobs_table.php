<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_jobs', function (Blueprint $table) {
            $table->id();
            // Nullable for imports (no source document yet).
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('direction'); // export | import
            $table->string('format');    // pdf | docx | ...
            $table->string('status')->default('pending'); // pending | processing | done | failed
            $table->string('result_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_jobs');
    }
};
