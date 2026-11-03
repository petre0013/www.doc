<?php

use App\Models\Document;
use App\Models\Workspace;
use App\Services\RenderDocument;
use Database\Factories\DocumentFactory;

/**
 * Guards the version-snapshot bug we fixed: each snapshot must store the HTML of
 * its OWN content, rendered before the snapshot is taken — never the stale html
 * left over from the previous save (which made the oldest version come out empty).
 */
test("each version's content_html matches its own content", function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'Runbook',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Alpha body.'),
    ]);
    $document = Document::firstWhere('title', 'Runbook');

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Bravo body.'),
    ]);

    // Use reorder() instead of orderBy() to strip the relation's default latest() scope.
    $versions = $document->fresh()->versions()->reorder('id')->get();
    expect($versions)->toHaveCount(2);

    // Every snapshot's cached html is a faithful render of that snapshot's content.
    foreach ($versions as $version) {
        expect($version->content_html)->toBe(RenderDocument::toHtml($version->content));
    }

    // And the oldest snapshot is its own content, not the next save bleeding back in.
    expect($versions->first()->content_html)
        ->toContain('Alpha body.')
        ->not->toContain('Bravo body.');
    expect($versions->last()->content_html)->toContain('Bravo body.');
});

test('the first snapshot is not empty when a page is created with content', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'Has Content',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Real content from the start.'),
    ]);

    $first = Document::firstWhere('title', 'Has Content')->versions()->reorder('id')->first();

    expect($first)->not->toBeNull();
    expect($first->content_html)->toContain('Real content from the start.');
});

test('a version snapshot records the page attachments as they were at save time', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title' => 'Doc with Attachments',
        'workspace_id' => $workspace->id,
        'content' => DocumentFactory::tiptap('Initial'),
    ]);
    
    $document = Document::firstWhere('title', 'Doc with Attachments');

    \App\Models\Attachment::create([
        'document_id' => $document->id,
        'disk' => 'local',
        'path' => 'dummy.pdf',
        'original_name' => 'Q1_Report.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
    ]);

    // Trigger a save so the DocumentObserver creates a snapshot
    $document->update(['title' => 'Doc with Attachments updated']);

    $snapshot = $document->versions()->latest('id')->first();
    
    expect($snapshot->attachments)->toHaveCount(1)
        ->and($snapshot->attachments[0]['original_name'])->toBe('Q1_Report.pdf');
});
