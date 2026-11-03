<?php

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Database\Factories\DocumentFactory;

/**
 * Tags are part of a version snapshot, so restoring a version is a FULL revert
 * (content + title + tags), not content-only. Snapshots store tag NAMES, which
 * survive tag rename/deletion.
 */

test('a version snapshot records the page tags as they were at save time', function () {
    login();
    $workspace = Workspace::factory()->create();
    $draft = Tag::factory()->create(['name' => 'draft']);
    $review = Tag::factory()->create(['name' => 'review']);

    $document = Document::factory()->create(['workspace_id' => $workspace->id]);
    $document->tags()->sync([$draft->id, $review->id]);

    // A content save snapshots a version; it should capture the current tags.
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Body with tags attached.'),
    ])->assertRedirect();

    $version = $document->fresh()->versions()->latest('id')->first();
    expect($version->tags)->toEqualCanonicalizing(['draft', 'review']);
});

test('a combined content-and-tags save snapshots the new tag set', function () {
    login();
    $workspace = Workspace::factory()->create();
    $old = Tag::factory()->create(['name' => 'old']);
    $new = Tag::factory()->create(['name' => 'new']);

    $document = Document::factory()->create(['workspace_id' => $workspace->id]);
    $document->tags()->sync([$old->id]);

    // Same request changes content AND swaps the tag — the snapshot must reflect
    // the tags as of this save, not the prior set.
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Retagged in the same save.'),
        'tags'    => [$new->id],
    ])->assertRedirect();

    $version = $document->fresh()->versions()->latest('id')->first();
    expect($version->tags)->toBe(['new']);
});

test('restoring a version reverts the tag set, adding back and removing as needed', function () {
    login();
    $workspace = Workspace::factory()->create();
    $alpha = Tag::factory()->create(['name' => 'alpha']);
    $beta  = Tag::factory()->create(['name' => 'beta']);

    $document = Document::factory()->create(['workspace_id' => $workspace->id]);

    // v1: tagged [alpha]
    $document->tags()->sync([$alpha->id]);
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('First revision.'),
    ]);
    $v1 = $document->fresh()->versions()->latest('id')->first();
    expect($v1->tags)->toBe(['alpha']);

    // Now the live page is tagged [beta] only.
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Second revision.'),
        'tags'    => [$beta->id],
    ]);
    expect($document->fresh()->tags->pluck('name')->all())->toBe(['beta']);

    // Restore v1 → tags revert to [alpha]: beta dropped, alpha re-added.
    $this->post("/documents/{$document->id}/versions/{$v1->id}/restore")->assertRedirect();

    expect($document->fresh()->tags->pluck('name')->all())->toBe(['alpha']);
});

test('restore recreates a tag that was deleted since the version was saved', function () {
    login();
    $workspace = Workspace::factory()->create();
    $temp = Tag::factory()->create(['name' => 'temporary']);

    $document = Document::factory()->create(['workspace_id' => $workspace->id]);
    $document->tags()->sync([$temp->id]);
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Tagged with a soon-to-be-deleted tag.'),
    ]);
    $version = $document->fresh()->versions()->latest('id')->first();

    // The tag is deleted entirely (pivot rows cascade away).
    $temp->delete();
    expect($document->fresh()->tags)->toHaveCount(0);

    $this->post("/documents/{$document->id}/versions/{$version->id}/restore")->assertRedirect();

    // A fresh tag with the same name is recreated and reattached.
    $tags = $document->fresh()->tags;
    expect($tags->pluck('name')->all())->toBe(['temporary']);
    expect(Tag::where('name', 'temporary')->count())->toBe(1);
});

test('the snapshot a restore itself creates carries the restored tags', function () {
    login();
    $workspace = Workspace::factory()->create();
    $keep = Tag::factory()->create(['name' => 'keep']);

    $document = Document::factory()->create(['workspace_id' => $workspace->id]);
    $document->tags()->sync([$keep->id]);
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Original.'),
    ]);
    $original = $document->fresh()->versions()->latest('id')->first();

    // Drift the live page away (untag, new content) then restore the original.
    $this->patch("/documents/{$document->id}", [
        'content' => DocumentFactory::tiptap('Drifted.'),
        'tags'    => [],
    ]);
    $this->post("/documents/{$document->id}/versions/{$original->id}/restore")->assertRedirect();

    // Restore changed content, so it snapshots a new version — which must record
    // the restored tags, keeping history self-consistent.
    $newest = $document->fresh()->versions()->latest('id')->first();
    expect($newest->id)->not->toBe($original->id);
    expect($newest->tags)->toBe(['keep']);
});
