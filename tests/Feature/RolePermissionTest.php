<?php

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Database\Factories\DocumentFactory;

/**
 * Role enforcement across the resource policies. The matrix is the same for
 * documents, workspaces and tags: everyone may view; admin + editor may
 * create/update; only admin may delete. Controllers enforce it via $this->authorize().
 */

// ── Documents ───────────────────────────────────────────────────────────────

test('a viewer may read a document but not create, update or delete', function () {
    login(role: 'viewer');
    $workspace = Workspace::factory()->create();
    $document = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->get("/documents/{$document->id}")->assertOk();

    $this->post('/documents', [
        'title' => 'Nope',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('x'),
    ])->assertForbidden();

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('edited'),
    ])->assertForbidden();

    $this->delete("/documents/{$document->id}")->assertForbidden();
});

test('an editor may create and update a document but not delete it', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->post('/documents', [
        'title' => 'Editor Page',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('body'),
    ])->assertRedirect();

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('edited'),
    ])->assertRedirect();

    $this->delete("/documents/{$document->id}")->assertForbidden();
});

test('an admin may delete a document', function () {
    login(); // admin
    $document = Document::factory()->create();

    $this->delete("/documents/{$document->id}")->assertRedirect();
    $this->assertSoftDeleted('documents', ['id' => $document->id]);
});

// ── Workspaces ──────────────────────────────────────────────────────────────

test('a viewer cannot create or delete a workspace', function () {
    login(role: 'viewer');
    $workspace = Workspace::factory()->create();

    $this->post('/workspaces', ['name' => 'Blocked'])->assertForbidden();
    $this->delete("/workspaces/{$workspace->id}")->assertForbidden();
});

test('an editor can create a workspace but not delete one', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();

    $this->post('/workspaces', ['name' => 'Editor Space'])->assertRedirect();
    $this->delete("/workspaces/{$workspace->id}")->assertForbidden();
});

test('only an admin can delete a workspace', function () {
    login(); // admin
    $workspace = Workspace::factory()->create();

    $this->delete("/workspaces/{$workspace->id}")->assertRedirect();
    $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
});

// ── Tags ────────────────────────────────────────────────────────────────────

test('a viewer cannot create a tag; an editor can', function () {
    login(role: 'viewer');
    $this->post('/tags', ['name' => 'Blocked'])->assertForbidden();

    login(role: 'editor');
    $this->post('/tags', ['name' => 'Allowed'])->assertRedirect();
    expect(Tag::firstWhere('name', 'Allowed'))->not->toBeNull();
});

test('only an admin can delete a tag', function () {
    login(role: 'editor');
    $tag = Tag::factory()->create();
    $this->delete("/tags/{$tag->id}")->assertForbidden();

    login(); // admin
    $this->delete("/tags/{$tag->id}")->assertRedirect();
});

// ── Trash ───────────────────────────────────────────────────────────────────

test('only an admin can view the trash', function () {
    login(role: 'viewer');
    $this->get('/trash')->assertForbidden();

    login(role: 'editor');
    $this->get('/trash')->assertForbidden();

    login(); // admin
    $this->get('/trash')->assertOk();
});

test('only an admin can empty the trash', function () {
    login(role: 'editor');
    $this->delete('/trash')->assertForbidden();

    login(); // admin
    $this->delete('/trash')->assertRedirect();
});

