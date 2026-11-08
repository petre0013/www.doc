<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Structured NIS2 registers, one table with per-type fields (App\Support\Registers). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_items', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('reference', 50)->nullable()->unique();
            $table->string('title');
            $table->string('status');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('due_at');
        });

        Schema::create('register_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('linked_item_id')->nullable()->constrained('register_items')->cascadeOnDelete();
            $table->unique(['register_item_id', 'document_id']);
            $table->unique(['register_item_id', 'linked_item_id']);
        });

        Schema::table('document_controls', function (Blueprint $table) {
            $table->boolean('ack_required')->default(false);
        });

        // No foreign keys: proof that someone read a policy outlives the page.
        Schema::create('document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('user_email');
            $table->timestamp('acknowledged_at');
            $table->unique(['document_version_id', 'user_id']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgements');
        Schema::table('document_controls', fn (Blueprint $table) => $table->dropColumn('ack_required'));
        Schema::dropIfExists('register_links');
        Schema::dropIfExists('register_items');
    }
};
