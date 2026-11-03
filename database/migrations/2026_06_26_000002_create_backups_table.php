<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per backup run — the admin UI polls these exactly like `conversion_jobs`.
 * The archive itself lives on a private disk (`local` by default, `s3` for the
 * off-host resilience milestone); `path`/`size_bytes`/`manifest` describe it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');  // pending | processing | done | failed
            $table->string('trigger')->default('manual');   // manual | scheduled
            $table->string('disk')->default('local');        // local | s3
            $table->string('path')->nullable();              // archive path on $disk
            $table->bigInteger('size_bytes')->nullable();
            $table->jsonb('manifest')->nullable();           // app/schema version, counts, per-file sha256
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
