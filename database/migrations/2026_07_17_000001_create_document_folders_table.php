<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A folder is NOT a page: it has no body, no slug and no URL, because it
        // can never be opened — that's the whole point of the concept. It owns no
        // content either, so it isn't soft-deleted (mirrors WorkspaceGroup):
        // deleting one reverts its pages to loose, and a lingering trashed row
        // would only get in the way. cascadeOnDelete fires on a workspace PURGE
        // only — a soft-deleted workspace keeps its folders, so restore brings
        // them back.
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // A root page belongs to at most one folder. Null = loose (top level),
        // which is how every existing document reads, so this is additive.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('parent_id');
            $table->index('folder_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The two invariants, held by the DATABASE rather than by every write
        // path remembering them. The design's own argument for a separate table
        // was that a missed filter fails silently; the same reasoning applies
        // here — a constraint cannot be forgotten by a future controller, job,
        // seeder or restore.

        // 1. Same workspace. Enforced with a composite FK instead of a plain
        //    folder_id one: (folder_id, workspace_id) must match a real folder's
        //    (id, workspace_id), so a page can never point at a folder living in
        //    another workspace. Needs this UNIQUE to be referenceable — id alone
        //    is already unique, but Postgres requires the exact column set.
        //    ON DELETE SET NULL (folder_id) is the DB backstop mirroring
        //    workspaces.group_id's nullOnDelete: deleting a folder un-files its
        //    pages rather than taking them down. The column list matters — a bare
        //    SET NULL would null workspace_id too, orphaning the document.
        DB::statement('ALTER TABLE document_folders ADD CONSTRAINT document_folders_id_workspace_id_unique UNIQUE (id, workspace_id)');
        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_folder_id_workspace_id_foreign '
            .'FOREIGN KEY (folder_id, workspace_id) REFERENCES document_folders (id, workspace_id) '
            .'ON DELETE SET NULL (folder_id)'
        );

        // 2. Root pages only. A subpage's folder is derived from its root
        //    ancestor, so its own folder_id must stay null — otherwise the tree
        //    goes ambiguous (a page in folder A whose parent sits in folder B)
        //    and the one-level model breaks down.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_folder_root_only CHECK (folder_id IS NULL OR parent_id IS NULL)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_folder_root_only');
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_folder_id_workspace_id_foreign');
            DB::statement('ALTER TABLE document_folders DROP CONSTRAINT IF EXISTS document_folders_id_workspace_id_unique');
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['folder_id']);
            $table->dropColumn('folder_id');
        });

        Schema::dropIfExists('document_folders');
    }
};
