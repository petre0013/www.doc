<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// M1 — data foundation. These assert the schema-level invariants directly
// (raw model writes, no controllers): the write paths that set folder_id land
// in M2, and the whole point of holding these in the database is that they bind
// every path, including ones not written yet.

test('existing documents read as loose', function () {
    $doc = Document::factory()->create();

    expect($doc->folder_id)->toBeNull()
        ->and($doc->folder)->toBeNull();
});

test('a root page can be filed in a folder of its own workspace', function () {
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);

    expect($page->fresh()->folder->name)->toBe($folder->name)
        ->and($folder->documents->pluck('id')->all())->toBe([$page->id]);
});

test('a folder belongs to its workspace and lists its pages in order', function () {
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);

    $second = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id, 'position' => 1]);
    $first  = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id, 'position' => 0]);

    expect($folder->workspace->id)->toBe($workspace->id)
        ->and($workspace->folders->pluck('id')->all())->toBe([$folder->id])
        ->and($folder->documents->pluck('id')->all())->toBe([$first->id, $second->id]);
});

// ── The two invariants ───────────────────────────────────────────────────────

test('a subpage cannot be filed in a folder', function () {
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $parent    = Document::factory()->create(['workspace_id' => $workspace->id]);

    // A subpage's folder is derived from its root ancestor; storing its own
    // would let a page sit in folder A while its parent sits in folder B.
    Document::factory()->create([
        'workspace_id' => $workspace->id,
        'parent_id'    => $parent->id,
        'folder_id'    => $folder->id,
    ]);
})->throws(QueryException::class);

test('a root page cannot be filed in another workspace\'s folder', function () {
    $mine      = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $elsewhere->id]);

    Document::factory()->create(['workspace_id' => $mine->id, 'folder_id' => $folder->id]);
})->throws(QueryException::class);

test('filing a page cannot be smuggled past the invariant by a later update', function () {
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $parent    = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child     = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);

    // The constraint binds updates too, not just inserts — M2's move/re-parent
    // paths inherit this for free.
    $child->folder_id = $folder->id;
    $child->save();
})->throws(QueryException::class);

// ── Deletion backstops ───────────────────────────────────────────────────────

test('deleting a folder un-files its pages instead of taking them down', function () {
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);

    $folder->delete();

    // The DB backstop mirrors workspaces.group_id's nullOnDelete. The app-level
    // behavior (and its audit event) is M2's; this proves content can't be lost
    // even if a future path forgets. Crucially workspace_id survives — a bare
    // SET NULL would have orphaned the page.
    $page->refresh();
    expect($page->exists)->toBeTrue()
        ->and($page->folder_id)->toBeNull()
        ->and($page->workspace_id)->toBe($workspace->id);
});

test('trashing a workspace keeps its folders, purging destroys them', function () {
    $workspace = Workspace::factory()->create();
    DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);

    // Soft delete: folders must survive so a restore brings the structure back.
    $workspace->delete();
    expect(DocumentFolder::where('workspace_id', $workspace->id)->count())->toBe(1);

    // Purge: cascadeOnDelete clears them.
    $workspace->forceDelete();
    expect(DocumentFolder::where('workspace_id', $workspace->id)->count())->toBe(0);
});

// ── M2: filing is structural, never an edit ──────────────────────────────────

test('a folder can be created, renamed and deleted', function () {
    login();
    $workspace = Workspace::factory()->create(['name' => 'Engineering']);

    $this->post("/workspaces/{$workspace->id}/folders", ['name' => 'Runbooks'])->assertRedirect();
    $folder = DocumentFolder::firstWhere('name', 'Runbooks');
    expect($folder->workspace_id)->toBe($workspace->id);

    $this->patch("/folders/{$folder->id}", ['name' => 'Playbooks'])->assertRedirect();
    expect($folder->fresh()->name)->toBe('Playbooks');

    $this->delete("/folders/{$folder->id}")->assertRedirect();
    expect(DocumentFolder::find($folder->id))->toBeNull();
});

test('folder create/rename/delete are audited', function () {
    login();
    $workspace = Workspace::factory()->create(['name' => 'Engineering']);

    $this->post("/workspaces/{$workspace->id}/folders", ['name' => 'Runbooks']);
    $folder = DocumentFolder::firstWhere('name', 'Runbooks');
    $created = AuditEvent::where('event', 'folder.created')->latest('id')->first();
    expect($created->context['name'])->toBe('Runbooks')
        ->and($created->context['workspace'])->toBe('Engineering');

    $this->patch("/folders/{$folder->id}", ['name' => 'Playbooks']);
    $renamed = AuditEvent::where('event', 'folder.renamed')->latest('id')->first();
    expect($renamed->context['from'])->toBe('Runbooks')
        ->and($renamed->context['to'])->toBe('Playbooks');

    $this->delete("/folders/{$folder->id}");
    $deleted = AuditEvent::where('event', 'folder.deleted')->latest('id')->first();
    // Subject is hard-deleted, so identity must ride in context or the morph dangles.
    expect($deleted->subject_id)->toBeNull()
        ->and($deleted->context['folder_id'])->toBe($folder->id)
        ->and($deleted->context['name'])->toBe('Playbooks');
});

test('filing a page into a folder does not bump updated_at or version', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id]);

    // Freeze a known-old timestamp so any Eloquent touch is unmistakable.
    DB::table('documents')->where('id', $page->id)
        ->update(['updated_at' => now()->subDays(3), 'version' => 4]);
    $before = Document::find($page->id);

    $this->patch("/documents/{$page->id}/folder", ['folder_id' => $folder->id])->assertRedirect();

    $after = $page->fresh();
    expect($after->folder_id)->toBe($folder->id)
        // A move must never read as an edit...
        ->and($after->updated_at->timestamp)->toBe($before->updated_at->timestamp)
        // ...nor trip the editor's optimistic lock into a false edit conflict.
        ->and($after->version)->toBe(4);
});

test('filing a page in and out is audited as a move, loose reads as null', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Runbooks']);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Restore procedure']);

    $this->patch("/documents/{$page->id}/folder", ['folder_id' => $folder->id]);
    $in = AuditEvent::where('event', 'document.moved')->latest('id')->first();
    expect($in->context['title'])->toBe('Restore procedure')
        ->and($in->context['from_folder'])->toBeNull()
        ->and($in->context['to_folder'])->toBe('Runbooks');

    $this->patch("/documents/{$page->id}/folder", ['folder_id' => null]);
    $out = AuditEvent::where('event', 'document.moved')->latest('id')->first();
    expect($out->context['from_folder'])->toBe('Runbooks')
        ->and($out->context['to_folder'])->toBeNull()
        ->and($page->fresh()->folder_id)->toBeNull();
});

test('a position-only refile is not audited', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);

    $before = AuditEvent::where('event', 'document.moved')->count();
    $this->patch("/documents/{$page->id}/folder", ['folder_id' => $folder->id, 'position' => 3]);

    // Same folder, new slot: layout noise, like sibling reorders.
    expect(AuditEvent::where('event', 'document.moved')->count())->toBe($before)
        ->and($page->fresh()->position)->toBe(3);
});

test('deleting a folder reverts its pages to loose without touching them', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);

    DB::table('documents')->where('id', $page->id)->update(['updated_at' => now()->subDays(3)]);
    $before = Document::find($page->id);

    $this->delete("/folders/{$folder->id}")->assertRedirect();

    $after = $page->fresh();
    // A folder is a label, not an owner of content.
    expect($after->trashed())->toBeFalse()
        ->and($after->folder_id)->toBeNull()
        ->and($after->updated_at->timestamp)->toBe($before->updated_at->timestamp);
});

// ── Guards: the DB constraints must never be how a user finds out ────────────

test('filing a subpage is rejected as a validation error, not a 500', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $parent    = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child     = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);

    $this->patch("/documents/{$child->id}/folder", ['folder_id' => $folder->id])
        ->assertSessionHasErrors('folder_id');
});

test('filing into another workspace\'s folder is rejected as a validation error', function () {
    login();
    $mine      = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $elsewhere->id]);
    $page      = Document::factory()->create(['workspace_id' => $mine->id]);

    $this->patch("/documents/{$page->id}/folder", ['folder_id' => $folder->id])
        ->assertSessionHasErrors('folder_id');
});

test('re-parenting a filed page un-files it instead of blowing up', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);
    $parent    = Document::factory()->create(['workspace_id' => $workspace->id]);
    $page      = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);

    // Root-only is DB-enforced, so move() must clear folder_id or this 500s.
    $this->patch("/documents/{$page->id}/move", ['parent_id' => $parent->id])->assertRedirect();

    expect($page->fresh()->folder_id)->toBeNull()
        ->and($page->fresh()->parent_id)->toBe($parent->id);
});

test('moving a filed page to another workspace un-files it', function () {
    login();
    $from   = Workspace::factory()->create();
    $to     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $from->id]);
    $page   = Document::factory()->create(['workspace_id' => $from->id, 'folder_id' => $folder->id]);

    // The folder lives in the old workspace — same-workspace is DB-enforced.
    $this->patch("/documents/{$page->id}/move", ['workspace_id' => $to->id])->assertRedirect();

    expect($page->fresh()->folder_id)->toBeNull()
        ->and($page->fresh()->workspace_id)->toBe($to->id);
});

test('a viewer cannot manage folders', function () {
    login(role: 'viewer');
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);

    $this->post("/workspaces/{$workspace->id}/folders", ['name' => 'Nope'])->assertForbidden();
    $this->patch("/folders/{$folder->id}", ['name' => 'Nope'])->assertForbidden();
    $this->delete("/folders/{$folder->id}")->assertForbidden();
});

// ── M3: the tree view ────────────────────────────────────────────────────────

test('the workspace show page exposes folders and each root page\'s folder_id', function () {
    login();
    $workspace = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Runbooks']);
    $filed     = Document::factory()->create(['workspace_id' => $workspace->id, 'folder_id' => $folder->id]);
    $loose     = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->get("/workspaces/{$workspace->id}")->assertOk()->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Workspaces/Show')
            ->has('folders', 1)
            ->where('folders.0.name', 'Runbooks')
            // The tree carries folder_id so the page can compose sections client-side.
            ->has('tree', 2)
    );
});

// ── M4: folders and loose pages share one top-level order ────────────────────

test('folders and loose pages reorder into one shared sequence', function () {
    login();
    $ws = Workspace::factory()->create();
    $fA = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'position' => 0]);
    $fB = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'position' => 1]);
    $loose = Document::factory()->create(['workspace_id' => $ws->id, 'position' => 0]);

    // Target order: folder B, loose page, folder A.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [
            ['type' => 'folder', 'id' => $fB->id],
            ['type' => 'page',   'id' => $loose->id],
            ['type' => 'folder', 'id' => $fA->id],
        ],
    ])->assertRedirect();

    expect($fB->fresh()->position)->toBe(0)
        ->and($loose->fresh()->position)->toBe(1)
        ->and($fA->fresh()->position)->toBe(2);
});

test('members reorder within their folder, preserving relative order', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $one    = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id, 'position' => 0]);
    $two    = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id, 'position' => 1]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $folder->id]],
        'folders' => [['id' => $folder->id, 'members' => [$two->id, $one->id]]],
    ])->assertRedirect();

    expect($folder->documents()->pluck('id')->all())->toBe([$two->id, $one->id])
        // Same folder, new slots: layout noise, so no move is recorded.
        ->and(AuditEvent::where('event', 'document.moved')->count())->toBe(0);
});

test('reordering the top level does not bump any updated_at', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $member = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id]);
    $loose  = Document::factory()->create(['workspace_id' => $ws->id]);

    DB::table('documents')->whereIn('id', [$member->id, $loose->id])->update(['updated_at' => now()->subDays(5)]);
    DB::table('document_folders')->where('id', $folder->id)->update(['updated_at' => now()->subDays(5)]);
    $memberBefore = $member->fresh()->updated_at->timestamp;
    $folderBefore = $folder->fresh()->updated_at->timestamp;

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $loose->id]],
        'folders' => [['id' => $folder->id, 'members' => [$member->id]]],
    ])->assertRedirect();

    expect($member->fresh()->updated_at->timestamp)->toBe($memberBefore)
        ->and($loose->fresh()->updated_at->timestamp)->toBe($memberBefore)
        ->and($folder->fresh()->updated_at->timestamp)->toBe($folderBefore);
});

test('reordering the top level records no audit event', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $loose  = Document::factory()->create(['workspace_id' => $ws->id]);

    $before = AuditEvent::count();
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $loose->id]],
    ])->assertRedirect();

    // Position-only, like sibling reorders — layout noise, deliberately unaudited.
    expect(AuditEvent::count())->toBe($before);
});

// --- Batched refiling: a drag between folders rides the same "Done" ----------

test('the batched save files a loose page into a folder, structurally and audited', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);
    $loose  = Document::factory()->create(['workspace_id' => $ws->id, 'title' => 'Restore procedure']);

    DB::table('documents')->where('id', $loose->id)->update(['updated_at' => now()->subDays(3), 'version' => 4]);
    $before = Document::find($loose->id);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $folder->id]],
        'folders' => [['id' => $folder->id, 'members' => [$loose->id]]],
    ])->assertRedirect();

    $after = $loose->fresh();
    expect($after->folder_id)->toBe($folder->id)
        // Structural — a refile must never read as an edit nor trip the lock.
        ->and($after->updated_at->timestamp)->toBe($before->updated_at->timestamp)
        ->and($after->version)->toBe(4);

    $event = AuditEvent::firstWhere('event', 'document.moved');
    expect($event->context)->toMatchArray(['from_folder' => null, 'to_folder' => 'Runbooks']);
});

test('presenting a filed page as a top-level item refiles it to loose and audits the move', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);
    $filed  = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [['type' => 'page', 'id' => $filed->id]],
    ])->assertRedirect();

    expect($filed->fresh()->folder_id)->toBeNull();

    $event = AuditEvent::firstWhere('event', 'document.moved');
    expect($event->context)->toMatchArray(['from_folder' => 'Runbooks', 'to_folder' => null]);
});

test('one save refiling several pages records one move event each', function () {
    login();
    $ws = Workspace::factory()->create();
    $fA = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Alpha']);
    $fB = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Beta']);
    $p1 = Document::factory()->create(['workspace_id' => $ws->id, 'title' => 'One', 'folder_id' => $fA->id]);
    $p2 = Document::factory()->create(['workspace_id' => $ws->id, 'title' => 'Two']);

    // p1: Alpha -> Beta; p2: loose -> Beta. Two refiles, one atomic save.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $fA->id], ['type' => 'folder', 'id' => $fB->id]],
        'folders' => [
            ['id' => $fA->id, 'members' => []],
            ['id' => $fB->id, 'members' => [$p1->id, $p2->id]],
        ],
    ])->assertRedirect();

    expect($p1->fresh()->folder_id)->toBe($fB->id)
        ->and($p2->fresh()->folder_id)->toBe($fB->id)
        ->and(AuditEvent::where('event', 'document.moved')->count())->toBe(2);

    $contexts = AuditEvent::where('event', 'document.moved')->get()->pluck('context');
    expect($contexts->firstWhere('title', 'One'))->toMatchArray(['from_folder' => 'Alpha', 'to_folder' => 'Beta']);
    expect($contexts->firstWhere('title', 'Two'))->toMatchArray(['from_folder' => null, 'to_folder' => 'Beta']);
});

test('nesting a currently-filed page in the same save clears its folder_id', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $parent = Document::factory()->create(['workspace_id' => $ws->id]);
    $filed  = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id]);

    // Drag the filed page under another page: root-only is DB-enforced, so this
    // must shed folder_id rather than 500.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'    => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $parent->id]],
        'subtrees' => [['id' => $filed->id, 'parent_id' => $parent->id, 'position' => 0]],
    ])->assertRedirect();

    expect($filed->fresh()->parent_id)->toBe($parent->id)
        ->and($filed->fresh()->folder_id)->toBeNull();
});

test('a page cannot hold both a reorder slot and a nesting slot', function () {
    login();
    $ws     = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $ws->id]);
    $page   = Document::factory()->create(['workspace_id' => $ws->id]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'    => [['type' => 'page', 'id' => $parent->id], ['type' => 'page', 'id' => $page->id]],
        'subtrees' => [['id' => $page->id, 'parent_id' => $parent->id, 'position' => 0]],
    ])->assertSessionHasErrors('subtrees');

    expect($page->fresh()->parent_id)->toBeNull();
});

test('a page cannot hold two top-level slots', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $page   = Document::factory()->create(['workspace_id' => $ws->id]);

    // Loose item AND a folder member — two positions/folders for one row.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $page->id]],
        'folders' => [['id' => $folder->id, 'members' => [$page->id]]],
    ])->assertSessionHasErrors('items');

    expect($page->fresh()->folder_id)->toBeNull();
});

test('a reorder cannot touch another workspace\'s folder', function () {
    login();
    $ws        = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $foreign   = DocumentFolder::factory()->create(['workspace_id' => $elsewhere->id]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [['type' => 'folder', 'id' => $foreign->id]],
    ])->assertSessionHasErrors('items');
});

test('a viewer cannot reorder the top level', function () {
    login(role: 'viewer');
    $ws = Workspace::factory()->create();

    $this->patch("/workspaces/{$ws->id}/folder-order", ['items' => []])->assertForbidden();
});

test('the top-level reorder can re-nest pages in the same atomic save', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $loose  = Document::factory()->create(['workspace_id' => $ws->id]);
    $child  = Document::factory()->create(['workspace_id' => $ws->id]); // currently root, will nest

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'    => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $loose->id]],
        'subtrees' => [['id' => $child->id, 'parent_id' => $loose->id, 'position' => 0]],
    ])->assertRedirect();

    expect($child->fresh()->parent_id)->toBe($loose->id)
        // Re-nesting is a structural edit → audited, unlike a pure order change.
        ->and(AuditEvent::where('event', 'workspace.restructured')->count())->toBe(1);
});

test('un-nesting a subpage to the top level clears its parent and is a restructure', function () {
    login();
    $ws     = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $ws->id]);
    $child  = Document::factory()->create(['workspace_id' => $ws->id, 'parent_id' => $parent->id]);

    DB::table('documents')->where('id', $child->id)->update(['updated_at' => now()->subDays(3), 'version' => 4]);
    $before = Document::find($child->id);

    // Present the subpage as a loose top-level item — the save makes it a root page.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [
            ['type' => 'page', 'id' => $parent->id],
            ['type' => 'page', 'id' => $child->id],
        ],
    ])->assertRedirect();

    $after = $child->fresh();
    expect($after->parent_id)->toBeNull()
        ->and($after->folder_id)->toBeNull()
        // Structural — un-nesting must not read as an edit nor trip the lock.
        ->and($after->updated_at->timestamp)->toBe($before->updated_at->timestamp)
        ->and($after->version)->toBe(4)
        // A re-parent is a restructure, not a folder move.
        ->and(AuditEvent::where('event', 'workspace.restructured')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'document.moved')->count())->toBe(0);
});

test('a subpage filed straight into a folder un-nests and moves in one save', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);
    $parent = Document::factory()->create(['workspace_id' => $ws->id]);
    $child  = Document::factory()->create(['workspace_id' => $ws->id, 'parent_id' => $parent->id]);

    // Drag the subpage directly onto a folder: parent_id must be cleared before
    // folder_id is set, or the root-only CHECK would 500.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'   => [['type' => 'folder', 'id' => $folder->id], ['type' => 'page', 'id' => $parent->id]],
        'folders' => [['id' => $folder->id, 'members' => [$child->id]]],
    ])->assertRedirect();

    $after = $child->fresh();
    expect($after->parent_id)->toBeNull()
        ->and($after->folder_id)->toBe($folder->id)
        // Filing into the folder is the notable action: one move, and the implicit
        // un-nest is not double-counted as a restructure.
        ->and(AuditEvent::where('event', 'document.moved')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'workspace.restructured')->count())->toBe(0);

    $event = AuditEvent::firstWhere('event', 'document.moved');
    expect($event->context)->toMatchArray(['from_folder' => null, 'to_folder' => 'Runbooks']);
});

// --- Deferred folder creation: a folder made in Edit mode persists on Done -----

test('the batched save creates a new folder and files a page into it', function () {
    login();
    $ws   = Workspace::factory()->create();
    $page = Document::factory()->create(['workspace_id' => $ws->id, 'title' => 'Restore procedure']);

    // Temp id -1 stands in for the client-side pending folder; the save creates it
    // and maps the id, all in one atomic request (nothing was POSTed before Done).
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'      => [['type' => 'folder', 'id' => -1]],
        'folders'    => [['id' => -1, 'members' => [$page->id]]],
        'newFolders' => [['id' => -1, 'name' => 'Runbooks']],
    ])->assertRedirect();

    $folder = DocumentFolder::firstWhere('name', 'Runbooks');
    expect($folder)->not->toBeNull()
        ->and($folder->workspace_id)->toBe($ws->id)
        ->and($page->fresh()->folder_id)->toBe($folder->id);

    // folder.created (at Done only) AND the page's move into the new folder.
    $created = AuditEvent::firstWhere('event', 'folder.created');
    expect($created->context)->toMatchArray(['name' => 'Runbooks', 'workspace' => $ws->name]);
    $moved = AuditEvent::firstWhere('event', 'document.moved');
    expect($moved->context)->toMatchArray(['to_folder' => 'Runbooks']);
});

test('a deferred new folder lands at the position it holds in the order', function () {
    login();
    $ws    = Workspace::factory()->create();
    $loose = Document::factory()->create(['workspace_id' => $ws->id, 'position' => 5]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'      => [['type' => 'folder', 'id' => -1], ['type' => 'page', 'id' => $loose->id]],
        'folders'    => [['id' => -1, 'members' => []]],
        'newFolders' => [['id' => -1, 'name' => 'Keys']],
    ])->assertRedirect();

    $folder = DocumentFolder::firstWhere('name', 'Keys');
    // First item -> position 0; the loose page it sits above -> 1.
    expect($folder->position)->toBe(0)
        ->and($loose->fresh()->position)->toBe(1);
});

test('one save can create several folders and map each temp id', function () {
    login();
    $ws = Workspace::factory()->create();
    $p1 = Document::factory()->create(['workspace_id' => $ws->id]);
    $p2 = Document::factory()->create(['workspace_id' => $ws->id]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'      => [['type' => 'folder', 'id' => -1], ['type' => 'folder', 'id' => -2]],
        'folders'    => [
            ['id' => -1, 'members' => [$p1->id]],
            ['id' => -2, 'members' => [$p2->id]],
        ],
        'newFolders' => [
            ['id' => -1, 'name' => 'Alpha'],
            ['id' => -2, 'name' => 'Beta'],
        ],
    ])->assertRedirect();

    expect($p1->fresh()->folder->name)->toBe('Alpha')
        ->and($p2->fresh()->folder->name)->toBe('Beta')
        ->and(AuditEvent::where('event', 'folder.created')->count())->toBe(2);
});

test('a folder-order referencing an undeclared new folder is rejected', function () {
    login();
    $ws = Workspace::factory()->create();

    // Temp id -1 in items but no newFolders entry to create it.
    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items' => [['type' => 'folder', 'id' => -1]],
    ])->assertSessionHasErrors('items');

    // Validation fails before the transaction — nothing is created.
    expect(DocumentFolder::where('workspace_id', $ws->id)->count())->toBe(0);
});

test('a nesting payload cannot introduce a cycle', function () {
    login();
    $ws = Workspace::factory()->create();
    $a  = Document::factory()->create(['workspace_id' => $ws->id]);
    $b  = Document::factory()->create(['workspace_id' => $ws->id]);

    $this->patch("/workspaces/{$ws->id}/folder-order", [
        'items'    => [],
        'subtrees' => [
            ['id' => $a->id, 'parent_id' => $b->id, 'position' => 0],
            ['id' => $b->id, 'parent_id' => $a->id, 'position' => 0],
        ],
    ])->assertSessionHasErrors('subtrees');

    expect($a->fresh()->parent_id)->toBeNull();
});

// ── M5: survival across workspace soft-delete / restore ──────────────────────

test('a page keeps its folder across a workspace trash and restore', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $page   = Document::factory()->create(['workspace_id' => $ws->id, 'folder_id' => $folder->id]);

    $ws->trashWithDocuments();
    // Folder is not soft-deleted, so it stays put while the workspace is trashed.
    expect(DocumentFolder::find($folder->id))->not->toBeNull();

    $ws->restoreWithDocuments();

    // The restored page is still filed — soft-delete never touched folder_id.
    expect(Document::find($page->id)->folder_id)->toBe($folder->id);
});

test('a newly created folder sorts to the top of the order', function () {
    login();
    $ws = Workspace::factory()->create();
    $existing = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'position' => 0]);
    Document::factory()->create(['workspace_id' => $ws->id, 'position' => 0]); // a loose page at the top

    $this->post("/workspaces/{$ws->id}/folders", ['name' => 'Newest'])->assertRedirect();

    $new = DocumentFolder::firstWhere('name', 'Newest');
    expect($new->position)->toBeLessThan($existing->position)
        ->and($new->position)->toBe($ws->folders()->min('position'));
});

// ── Creating / importing straight into a folder ──────────────────────────────

test('a page can be created directly inside a folder', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);

    $this->post('/documents', [
        'title'        => 'Restore procedure',
        'workspace_id' => $ws->id,
        'folder_id'    => $folder->id,
    ])->assertRedirect();

    $page = Document::firstWhere('title', 'Restore procedure');
    expect($page->folder_id)->toBe($folder->id)
        ->and($page->parent_id)->toBeNull()
        // Top of its folder, like every other new page is top of its scope.
        ->and($page->position)->toBe($folder->documents()->min('position'));
});

test('creating into a folder is ONE created event carrying the folder, not a move', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);

    $this->post('/documents', [
        'title'        => 'Restore procedure',
        'workspace_id' => $ws->id,
        'folder_id'    => $folder->id,
    ]);

    $created = AuditEvent::where('event', 'document.created')->latest('id')->first();
    expect($created->context['title'])->toBe('Restore procedure')
        ->and($created->context['folder'])->toBe('Runbooks')
        ->and(AuditEvent::where('event', 'document.moved')->count())->toBe(0);
});

test('a page created loose records no folder in its audit context', function () {
    login();
    $ws = Workspace::factory()->create();

    $this->post('/documents', ['title' => 'Loose page', 'workspace_id' => $ws->id]);

    $created = AuditEvent::where('event', 'document.created')->latest('id')->first();
    expect($created->context)->not->toHaveKey('folder');
});

test('creating a page rejects a folder from another workspace', function () {
    login();
    $mine      = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $folder    = DocumentFolder::factory()->create(['workspace_id' => $elsewhere->id]);

    $this->post('/documents', [
        'title'        => 'Sneaky',
        'workspace_id' => $mine->id,
        'folder_id'    => $folder->id,
    ])->assertSessionHasErrors('folder_id');

    expect(Document::where('title', 'Sneaky')->exists())->toBeFalse();
});

test('creating a page rejects being a subpage AND a folder member', function () {
    login();
    $ws     = Workspace::factory()->create();
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id]);
    $parent = Document::factory()->create(['workspace_id' => $ws->id]);

    $this->post('/documents', [
        'title'        => 'Confused',
        'workspace_id' => $ws->id,
        'parent_id'    => $parent->id,
        'folder_id'    => $folder->id,
    ])->assertSessionHasErrors('folder_id');

    expect(Document::where('title', 'Confused')->exists())->toBeFalse();
});
