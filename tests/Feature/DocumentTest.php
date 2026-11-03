<?php

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\DocumentFactory;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

test('a document can be created under a workspace', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'VPN Runbook',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Hello world.'),
    ])->assertRedirect();

    $document = Document::firstWhere('title', 'VPN Runbook');
    expect($document)->not->toBeNull();
    expect($document->slug)->toBe('vpn-runbook');
    expect($document->workspace_id)->toBe($workspace->id);
});

test('creating a document records authorship and an initial version', function () {
    $user = login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'Audited Page',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Initial body.'),
    ]);

    $document = Document::firstWhere('title', 'Audited Page');
    expect($document->created_by_id)->toBe($user->id);
    expect($document->versions()->count())->toBe(1);
});

test('a version snapshotted without an auth session is attributed to the importer', function () {
    // Mirrors a DOCX/PDF import: ImportController stamps the importer on both
    // author columns and creates a placeholder (empty => no initial version)...
    $importer = User::factory()->create();

    $document = Document::factory()->create([
        'title'         => 'Importing document…',
        'content'       => ['type' => 'doc', 'content' => []],
        'created_by_id' => $importer->id,
        'updated_by_id' => $importer->id,
    ]);
    expect($document->versions()->count())->toBe(0);

    // ...then ImportDocumentJob writes the converted content on a queue worker,
    // where there is no authenticated user (Auth::id() is null).
    expect(Auth::id())->toBeNull();
    $document->content = DocumentFactory::tiptap('Converted body text.');
    $document->save();

    // The snapshot must credit the importer via the updated_by_id fallback rather
    // than record a null author. Guards the DocumentObserver::snapshotVersion fix.
    $version = $document->versions()->sole();
    expect($version->created_by_id)->toBe($importer->id);
});

test('creating an empty document records no version until content is added', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'Blank Page',
        'workspace_id' => $workspace->id,
    ]);

    $document = Document::firstWhere('title', 'Blank Page');
    // The blank initial state is not snapshotted — history starts at first save.
    expect($document->versions()->count())->toBe(0);

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('First real content.'),
    ])->assertRedirect();

    expect($document->fresh()->versions()->count())->toBe(1);
});

test('editing content snapshots a new version', function () {
    login();
    $document = Document::factory()->create();

    expect($document->versions()->count())->toBe(1);

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Revised body.'),
    ])->assertRedirect();

    expect($document->fresh()->versions()->count())->toBe(2);
});

test('changing only position does not snapshot a version', function () {
    login();
    $document = Document::factory()->create();

    $this->patch("/documents/{$document->id}", ['position' => 5])->assertRedirect();

    expect($document->fresh()->position)->toBe(5);
    expect($document->versions()->count())->toBe(1);
});

test('the document show page renders the read view', function () {
    login();
    $document = Document::factory()->create();

    $this->get("/documents/{$document->id}")->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('Documents/Show')
            ->where('document.id', $document->id)
            ->has('versionsCount')
    );
});

test('the document show page exposes direct children for the folder view', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $grandchildHolder = Document::factory()->create([
        'workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 0,
    ]);
    Document::factory()->create([
        'workspace_id' => $workspace->id, 'parent_id' => $grandchildHolder->id,
    ]);
    Document::factory()->create([
        'workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 1,
    ]);

    $this->get("/documents/{$parent->id}")->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('Documents/Show')
            ->has('children', 2)
            // children_count marks a child that is itself a folder.
            ->where('children.0.id', $grandchildHolder->id)
            ->where('children.0.children_count', 1)
            ->where('children.1.children_count', 0)
    );
});

test('a non-numeric id 404s instead of hitting the database', function () {
    login();

    // Without the digit route constraints these would pass "abc" into a bigint
    // lookup and 500 with a Postgres QueryException.
    $this->get('/documents/abc')->assertNotFound();
    $this->get('/workspaces/abc')->assertNotFound();
    $this->get('/documents/1/versions/abc')->assertNotFound();
});

test('the document show page surfaces backlinks from pages that link to it', function () {
    login();
    $workspace = Workspace::factory()->create();
    $target = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Firewall Rules']);
    Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Onboarding Runbook',
        'content' => DocumentFactory::tiptap('Confirm the [[Firewall Rules]] before granting access.'),
    ]);

    $this->get("/documents/{$target->id}")->assertInertia(
        fn (Assert $page) => $page
            ->has('backlinks', 1)
            ->where('backlinks.0.title', 'Onboarding Runbook')
    );
});

test('a document is soft-deleted, not destroyed', function () {
    login();
    $document = Document::factory()->create();

    $this->delete("/documents/{$document->id}")->assertRedirect();

    $this->assertSoftDeleted('documents', ['id' => $document->id]);
});

test('deleting a parent cascades to its subtree', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);

    $this->delete("/documents/{$parent->id}")->assertRedirect();

    $this->assertSoftDeleted('documents', ['id' => $parent->id]);
    $this->assertSoftDeleted('documents', ['id' => $child->id]);
});

test('a trashed document can be restored with its subtree', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);
    $parent->trashSubtree();

    $this->post("/trash/documents/{$parent->id}/restore")->assertRedirect();

    expect(Document::find($parent->id))->not->toBeNull();
    expect(Document::find($child->id))->not->toBeNull();
});

test('restoring a subtree leaves a pre-trashed child in the trash', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $kept   = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);
    $alreadyGone = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);

    // This child was trashed on its own before its parent was trashed.
    $alreadyGone->trashSubtree();
    $parent->trashSubtree();

    $this->post("/trash/documents/{$parent->id}/restore")->assertRedirect();

    expect(Document::find($kept->id))->not->toBeNull();
    $this->assertSoftDeleted('documents', ['id' => $alreadyGone->id]);
});

test('force-deleting a trashed document destroys it permanently', function () {
    login();
    $document = Document::factory()->create();
    $document->trashSubtree();

    $this->delete("/trash/documents/{$document->id}")->assertRedirect();

    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
});

test('trashed documents do not appear in search', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Findable VPN Runbook',
    ]);

    $this->get('/search?q=Findable')
        ->assertInertia(fn (Assert $page) => $page->has('results', 1));

    $document->trashSubtree();

    $this->get('/search?q=Findable')
        ->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('document title is required on create', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', ['workspace_id' => $workspace->id, 'title' => ''])
        ->assertSessionHasErrors('title');
});

test('wiki-links resolve to targets and produce backlinks', function () {
    login();
    $workspace = Workspace::factory()->create();
    $target = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Firewall']);

    $source = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Edge',
        'content' => DocumentFactory::tiptap('Configured per [[Firewall]] and [[Ghost Page]].'),
    ]);

    expect($source->outgoingLinks()->count())->toBe(2);
    expect($target->backlinks()->count())->toBe(1);
    expect($target->backlinks()->first()->source_document_id)->toBe($source->id);

    // The unresolved link is retained with a null target.
    expect($source->outgoingLinks()->whereNull('target_document_id')->count())->toBe(1);
});

test('wiki-links with explicit target_id bypass title ambiguity', function () {
    login();
    $workspace = Workspace::factory()->create();
    
    $olderDuplicate = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Duplicate Title',
        'created_at' => now()->subDay(),
    ]);

    $newerDuplicate = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Duplicate Title',
        'created_at' => now(),
    ]);

    $source = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Linking Page',
        'content' => [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'wikiLink',
                            'attrs' => [
                                'title' => 'Duplicate Title',
                                'target_id' => $newerDuplicate->id,
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]);

    // Should link specifically to the newer duplicate because of target_id
    expect($source->outgoingLinks()->first()->target_document_id)->toBe($newerDuplicate->id);
    
    // Now test fallback logic (no explicit target_id)
    $source2 = Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Linking Page 2',
        'content' => DocumentFactory::tiptap('Linking to [[Duplicate Title]] via typed text.')
    ]);
    
    // Should link to the older duplicate because of created_at ASC fallback
    expect($source2->outgoingLinks()->first()->target_document_id)->toBe($olderDuplicate->id);
});

// ── M4/M6: Edit-mode inline rename + new-page-at-top ─────────────────────────

test('a new root page lands at the top of the workspace tree', function () {
    login();
    $workspace = Workspace::factory()->create();
    Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);

    $this->post('/documents', [
        'title' => 'Newest',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Hi.'),
    ])->assertRedirect();

    $new = Document::firstWhere('title', 'Newest');
    // Below every current sibling's position, so it sorts first — not appended.
    expect($new->position)->toBeLessThan(0)
        ->and($new->position)->toBe(Document::where('workspace_id', $workspace->id)->min('position'));
});

test('a new root page lands above the workspace folders, not just the loose pages', function () {
    login();
    $workspace = Workspace::factory()->create();
    // Folders share ONE top-level order with loose root pages and take the top
    // slots; a new page must clear them, not just the lowest loose page.
    DocumentFolder::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    DocumentFolder::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 2]);

    $this->post('/documents', [
        'title' => 'Newest',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Hi.'),
    ])->assertRedirect();

    $new = Document::firstWhere('title', 'Newest');
    // Must sit below the top folder (position 0), i.e. negative — not at 1, which
    // would render between the two folders.
    expect($new->position)->toBeLessThan(0);
});

test('a new subpage lands at the top of its parent\'s children', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 0]);

    $this->post('/documents', [
        'title' => 'First child',
        'workspace_id' => $workspace->id,
        'parent_id' => $parent->id,
        'content' => DocumentFactory::tiptap('Hi.'),
    ])->assertRedirect();

    $new = Document::firstWhere('title', 'First child');
    expect($new->position)->toBeLessThan(0);
});

test('the inline rename endpoint updates the title and stays put', function () {
    login();
    $workspace = Workspace::factory()->create();
    $page = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Old name']);

    $res = $this->patch("/documents/{$page->id}/rename", ['title' => 'New name']);
    $res->assertRedirect(); // back(), not a redirect to the document editor

    expect($page->fresh()->title)->toBe('New name');
});

test('inline rename requires a non-empty title', function () {
    login();
    $workspace = Workspace::factory()->create();
    $page = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Keep me']);

    $this->patch("/documents/{$page->id}/rename", ['title' => ''])
        ->assertSessionHasErrors('title');

    expect($page->fresh()->title)->toBe('Keep me');
});

test('a viewer cannot rename a page', function () {
    login(role: 'viewer');
    $workspace = Workspace::factory()->create();
    $page = Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Locked']);

    $this->patch("/documents/{$page->id}/rename", ['title' => 'Hacked'])->assertForbidden();

    expect($page->fresh()->title)->toBe('Locked');
});
