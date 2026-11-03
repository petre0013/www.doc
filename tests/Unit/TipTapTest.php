<?php

use App\Support\TipTap;
use Database\Factories\DocumentFactory;

test('plainText concatenates nested text nodes', function () {
    $doc = DocumentFactory::tiptap('Hello world.');
    expect(TipTap::plainText($doc))->toBe('Hello world.');
});

test('wikiLinkTargets extracts and de-duplicates [[references]] using plain text', function () {
    $doc = DocumentFactory::tiptap('See [[Firewall]], [[VPN]] and [[Firewall]] again.');

    expect(TipTap::wikiLinkTargets($doc))->toBe([
        ['title' => 'Firewall', 'target_id' => null],
        ['title' => 'VPN', 'target_id' => null],
    ]);
});

test('wikiLinkTargets extracts target_id from custom nodes', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'wikiLink',
                        'attrs' => ['title' => 'Backend API', 'target_id' => 42],
                    ],
                    [
                        'type' => 'wikiLink',
                        'attrs' => ['title' => 'Backend API', 'target_id' => 42],
                    ],
                    [
                        'type' => 'wikiLink',
                        'attrs' => ['title' => 'Legacy Link'],
                    ]
                ]
            ]
        ]
    ];

    expect(TipTap::wikiLinkTargets($doc))->toBe([
        ['title' => 'Backend API', 'target_id' => 42],
        ['title' => 'Legacy Link', 'target_id' => null],
    ]);
});

test('wikiLinkTargets returns nothing when there are no links', function () {
    expect(TipTap::wikiLinkTargets(DocumentFactory::tiptap('plain text')))->toBe([]);
    expect(TipTap::wikiLinkTargets(null))->toBe([]);
});
