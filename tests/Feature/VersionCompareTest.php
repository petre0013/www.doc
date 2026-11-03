<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Workspace;

/** A document with two snapshots: "first body" then "second body". */
function docWithHistory(): Document
{
    $workspace = Workspace::factory()->create();
    $document = Document::factory()->for($workspace)->create(['title' => 'Compared']);

    $document->update(['content' => Database\Factories\DocumentFactory::tiptap('first body')]);
    $document->update(['content' => Database\Factories\DocumentFactory::tiptap('second body wording')]);

    return $document->fresh();
}

test('comparing two versions renders the compare page with a body diff', function () {
    login(role: 'editor');
    $document = docWithHistory();
    [$older, $newer] = $document->versions()->reorder('id')->get()->all();

    $this->get("/documents/{$document->id}/versions/compare?from={$older->id}&to={$newer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Versions/Compare')
            ->where('mode', 'versions')
            ->where('left.kind', 'version')
            ->where('right.kind', 'version')
            ->where('diff.body.changed', true)
            ->where('diff.identical', false));
});

test('a version can be compared against the current page state', function () {
    login(role: 'editor');
    $document = docWithHistory();
    $older = $document->versions()->reorder('id')->first();

    $this->get("/documents/{$document->id}/versions/compare?from={$older->id}&to=current")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('right.kind', 'current')
            ->where('right.title', $document->title)
            ->where('diff.body.changed', true));
});

test('compare defaults to the latest snapshot against current', function () {
    login(role: 'editor');
    $document = docWithHistory();

    $this->get("/documents/{$document->id}/versions/compare")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('left.kind', 'version')
            ->where('right.kind', 'current'));
});

test('a version belonging to another document is a 404', function () {
    login(role: 'editor');
    $document = docWithHistory();
    $other = docWithHistory();
    $foreign = $other->versions()->first();

    $this->get("/documents/{$document->id}/versions/compare?from={$foreign->id}&to=current")
        ->assertNotFound();
});

test('garbage version params are a 404', function () {
    login(role: 'editor');
    $document = docWithHistory();

    $this->get("/documents/{$document->id}/versions/compare?from=banana&to=current")
        ->assertNotFound();
});

test('two pages can be compared cross-document', function () {
    login(role: 'editor');
    $left = docWithHistory();
    $right = docWithHistory();
    $right->update(['title' => 'Other Page']);

    $this->get("/documents/compare?left={$left->id}&right={$right->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Versions/Compare')
            ->where('mode', 'documents')
            ->where('left.kind', 'document')
            ->where('right.title', 'Other Page'));

    $this->get("/documents/compare?left={$left->id}&right=999999")->assertNotFound();
    $this->get("/documents/compare?left={$left->id}")->assertRedirect(); // validation error
});

test('guests are redirected and viewers can read comparisons', function () {
    $document = docWithHistory(); // factory writes need an author-less path
    $this->get("/documents/{$document->id}/versions/compare")->assertRedirect('/login');

    login(role: 'viewer');
    $this->get("/documents/{$document->id}/versions/compare")->assertOk();
});

test('version snapshots record a change summary against their predecessor', function () {
    login(role: 'editor');
    $document = docWithHistory();
    [$first, $second] = $document->versions()->reorder('id')->get()->all();

    expect($first->summary)->toBeNull() // no baseline for the first snapshot
        ->and($second->summary['words_added'])->toBeGreaterThan(0)
        ->and($second->summary['words_removed'])->toBeGreaterThan(0)
        ->and($second->summary['diagram_changed'])->toBeFalse();
});

test('viewing a comparison writes no audit events', function () {
    login(role: 'editor');
    $document = docWithHistory();
    $before = AuditEvent::count();

    $this->get("/documents/{$document->id}/versions/compare")->assertOk();

    expect(AuditEvent::count())->toBe($before);
});
