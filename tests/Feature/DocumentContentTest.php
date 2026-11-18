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

/**
 * Copying a page's content in the browser absolutizes <img src>; pasting it
 * back stored `https://<host>/storage/assets/…`, which breaks (and on HTTP
 * deployments fails TLS on :443) as soon as the instance is reached under a
 * different scheme/host. Saves must store our own assets root-relative.
 */
test('the save path stores absolute URLs to our own assets as /storage/ paths', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    \Illuminate\Support\Facades\Storage::disk('public')->put('assets/abc123.png', 'png');

    login();
    $document = Document::factory()->create();

    $image = fn (string $src) => ['type' => 'image', 'attrs' => ['src' => $src]];

    $this->patch("/documents/{$document->id}", [
        'title' => $document->title,
        'content' => ['type' => 'doc', 'content' => [
            $image('https://10.0.0.5/storage/assets/abc123.png'),
            $image('http://docs.corp.lan:8080/storage/assets/abc123.png?x=1'),
            // Not ours (no such file) and external images: left untouched.
            $image('https://other.example/storage/assets/missing.png'),
            $image('https://cdn.example/logo.png'),
        ]],
    ])->assertRedirect();

    $fresh = $document->fresh();
    $srcs = array_map(fn ($n) => $n['attrs']['src'], $fresh->content['content']);

    expect($srcs)->toBe([
        '/storage/assets/abc123.png',
        '/storage/assets/abc123.png',
        'https://other.example/storage/assets/missing.png',
        'https://cdn.example/logo.png',
    ])->and($fresh->content_html)->not->toContain('https://10.0.0.5');
});

test('relativizeAssetUrl refuses path traversal', function () {
    \Illuminate\Support\Facades\Storage::fake('public');

    expect(TipTap::relativizeAssetUrl('https://h/storage/../.env'))->toBe('https://h/storage/../.env')
        ->and(TipTap::relativizeAssetUrl('/storage/assets/x.png'))->toBe('/storage/assets/x.png');
});
