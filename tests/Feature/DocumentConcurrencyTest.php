<?php

use App\Models\Document;
use App\Models\Tag;
use App\Support\TipTap;
use Database\Factories\DocumentFactory;

test('a stale save is rejected with a conflict and leaves the document unchanged', function () {
    login();
    $document = Document::factory()->create();
    $base = $document->version;

    // Someone else edits the page after our editor loaded it.
    $document->update(['content' => DocumentFactory::tiptap('Their newer body.')]);
    expect($document->fresh()->version)->toBe($base + 1);

    // Our save still carries the old base_version — it must be rejected.
    $this->patch("/documents/{$document->id}", [
        'content'      => DocumentFactory::tiptap('My conflicting body.'),
        'base_version' => $base,
    ])->assertRedirect()->assertSessionHas('saveConflict');

    $fresh = $document->fresh();
    // Nothing of ours was written: version unchanged, their content intact.
    expect($fresh->version)->toBe($base + 1);
    expect(TipTap::plainText($fresh->content))->toContain('Their newer body');
    expect($fresh->versions()->count())->toBe(2); // create + their edit only
});

test('a save with a matching base version succeeds and bumps the version', function () {
    login();
    $document = Document::factory()->create();

    $this->patch("/documents/{$document->id}", [
        'content'      => DocumentFactory::tiptap('Fresh body.'),
        'base_version' => $document->version,
    ])->assertRedirect()->assertSessionMissing('saveConflict');

    $fresh = $document->fresh();
    expect($fresh->version)->toBe(2);
    expect(TipTap::plainText($fresh->content))->toContain('Fresh body');
});

test('a forced save overwrites despite a stale base version', function () {
    login();
    $document = Document::factory()->create();
    $base = $document->version;

    $document->update(['content' => DocumentFactory::tiptap('Their body.')]); // version bumps

    $this->patch("/documents/{$document->id}", [
        'content'      => DocumentFactory::tiptap('Mine wins.'),
        'base_version' => $base,
        'force'        => true,
    ])->assertRedirect()->assertSessionMissing('saveConflict');

    expect(TipTap::plainText($document->fresh()->content))->toContain('Mine wins');
});

test('a save without a base version overwrites (backward compatible)', function () {
    login();
    $document = Document::factory()->create();

    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('No base version supplied.'),
    ])->assertRedirect()->assertSessionMissing('saveConflict');

    expect(TipTap::plainText($document->fresh()->content))->toContain('No base version supplied');
});

test('reordering does not bump the document version', function () {
    login();
    $document = Document::factory()->create();
    $version = $document->version;

    $this->patch("/documents/{$document->id}", ['position' => 5])->assertRedirect();

    expect($document->fresh()->version)->toBe($version);
});

test('a tags-only save does not bump the document version', function () {
    login();
    $document = Document::factory()->create();
    $tag = Tag::factory()->create();
    $version = $document->version;

    $this->patch("/documents/{$document->id}", ['tags' => [$tag->id]])->assertRedirect();

    expect($document->fresh()->version)->toBe($version);
    expect($document->tags()->count())->toBe(1);
});
