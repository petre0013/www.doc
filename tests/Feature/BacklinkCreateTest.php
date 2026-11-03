<?php

use App\Models\Document;
use App\Models\Link;

test('creating a page heals inbound broken wiki-links to its title', function () {
    login();

    $source = Document::factory()->create();
    // Save a wiki-link to a page that does not exist yet — the observer's
    // syncLinks() records it as a broken Link (null target).
    $source->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'wikiLink', 'attrs' => ['title' => 'Runbook']],
        ]],
    ]]]);

    $link = Link::where('source_document_id', $source->id)
        ->where('target_title', 'Runbook')->firstOrFail();
    expect($link->target_document_id)->toBeNull();

    // Creating the target page must resolve the inbound broken link.
    $target = Document::factory()->create(['title' => 'Runbook']);

    expect($link->fresh()->target_document_id)->toBe($target->id);
});

test('creating a same-titled page does not steal an already-resolved link', function () {
    login();

    $existing = Document::factory()->create(['title' => 'Shared']);
    $source   = Document::factory()->create();
    $source->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'wikiLink', 'attrs' => ['title' => 'Shared']],
        ]],
    ]]]);

    $link = Link::where('source_document_id', $source->id)
        ->where('target_title', 'Shared')->firstOrFail();
    expect($link->target_document_id)->toBe($existing->id);

    // A second page with the same title must NOT re-point the resolved link.
    Document::factory()->create(['title' => 'Shared']);

    expect($link->fresh()->target_document_id)->toBe($existing->id);
});
