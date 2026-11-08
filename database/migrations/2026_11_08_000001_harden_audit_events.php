<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the audit trail usable as evidence (NIS2):
 *  - actor name/email are stored on each event, so identity survives the account;
 *  - user_id loses its FK, because ON DELETE SET NULL rewrote history;
 *  - prev_hash/row_hash form a tamper-evident chain (see App\Support\AuditChain);
 *  - DB triggers enforce append-only below the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->index('user_id');
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64)->nullable();
        });

        DB::statement('UPDATE audit_events SET actor_name = users.name, actor_email = users.email
                       FROM users WHERE audit_events.user_id = users.id');

        // DELETE is allowed only inside AuditChain::withMaintenance (retention
        // pruning). UPDATE and TRUNCATE are never allowed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.audit_maintenance', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'audit_events is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_no_change BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();

            CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_events_no_change ON audit_events;
            DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events;
            DROP FUNCTION IF EXISTS audit_events_append_only();
        SQL);

        DB::statement('UPDATE audit_events SET user_id = NULL
                       WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)');

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn(['actor_name', 'actor_email', 'prev_hash', 'row_hash']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
