<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document control: optional per page. document_controls holds the current
 * state; document_control_events is the append-only sign-off record, with no
 * foreign keys so it outlives a purged page or a removed account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('doc_number', 50)->nullable()->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('classification')->default('internal');
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('review_interval_months')->default(12);
            $table->date('next_review_at')->nullable();
            $table->foreignId('approved_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('pending_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('next_review_at');
        });

        Schema::create('document_control_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->string('action'); // submitted | approved | rejected | reviewed | retired
            $table->string('document_title');
            $table->string('doc_number', 50)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at');

            $table->index(['document_id', 'id']);
            $table->index(['action', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_control_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'document_control_events is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER document_control_events_no_change BEFORE UPDATE OR DELETE ON document_control_events
                FOR EACH ROW EXECUTE FUNCTION document_control_events_append_only();

            CREATE TRIGGER document_control_events_no_truncate BEFORE TRUNCATE ON document_control_events
                FOR EACH STATEMENT EXECUTE FUNCTION document_control_events_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_control_events');
        DB::unprepared('DROP FUNCTION IF EXISTS document_control_events_append_only()');
        Schema::dropIfExists('document_controls');
    }
};
