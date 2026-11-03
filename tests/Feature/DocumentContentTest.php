<?php

use App\Models\Document;
use App\Support\TipTap;

/**
 * The save path must normalise content so the canonical stored JSON is always
 * valid for ProseMirror — an empty text node ({text:null} or '') otherwise
 * blanks the page in the editor. The DocumentObserver strips them on every
 * write, so no controller/import/seeder path can persist one.
 */
test('the save path strips invalid empty text nodes from content', function () {
    login();
    $document = Document::factory()->create();

    $this->patch("/documents/{$document->id}", [
        'title' => $document->title,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'See '],
                ['type' => 'wikiLink', 'attrs' => ['title' => 'Other Page']],
                ['type' => 'text', 'text' => null],  // invalid — must be stripped
                ['type' => 'text', 'text' => ''],    // invalid — must be stripped
            ]],
        ]],
    ])->assertRedirect();

    $content = $document->fresh()->content;

    // Only the real text node and the wiki-link survive.
    expect($content['content'][0]['content'])->toHaveCount(2)
        ->and(json_encode($content))
            ->not->toContain('"text":null')
            ->not->toContain('"text":""');
});

test('normalize is a no-op on already-valid content', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
    ]];

    expect(TipTap::normalize($doc))->toBe($doc);
});
