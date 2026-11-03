<?php

use App\Services\RenderDocument;

/**
 * Schema parity guard.
 *
 * The editor schema lives at TWO ends that must agree: the JS editor
 * (`resources/js/components/editor/TipTapEditor.jsx`) and the PHP renderer
 * (`App\Services\RenderDocument`). A node/mark registered in the editor but
 * NOT in RenderDocument is silently dropped from the read view, every export,
 * and the search vector — it errors nowhere, so review never catches it.
 *
 * This test renders ONE fixture document containing every persisted custom
 * node and mark, then asserts each one's signature survives the JSON -> HTML
 * pass. If you teach the editor a new node/mark, add it to `fixtureDoc()`
 * (now in tests/Pest.php — DocumentDiffTest shares it) and assert it here —
 * and the test fails loudly the day RenderDocument forgets its half.
 * (Editor-only extensions that persist no content — Placeholder,
 * SlashCommands, ImageUpload — have no server counterpart and deliberately
 * aren't covered.)
 *
 * Signatures below were captured from real RenderDocument output, not guessed.
 */

beforeEach(function () {
    $this->html = RenderDocument::toHtml(fixtureDoc());
});

test('StarterKit marks survive rendering', function () {
    expect($this->html)
        ->toContain('<strong>bold')      // bold
        ->toContain('<em>italic')        // italic
        ->toContain('<s>strike')         // strike
        ->toContain('<code>code');       // inline code
});

test('underline mark survives rendering', function () {
    // Underline is subclassed/registered explicitly in RenderDocument.
    expect($this->html)->toContain('<u>under');
});

test('text colour (ColoredTextStyleMark) survives rendering', function () {
    // tiptap-php's base TextStyle has no color attr; the subclass adds it.
    expect($this->html)->toContain('color: #ff0000');
});

test('highlight mark survives rendering', function () {
    expect($this->html)
        ->toContain('<mark')
        ->toContain('background-color: #ffff00');
});

test('link mark survives rendering', function () {
    expect($this->html)->toContain('href="https://example.com"');
});

test('block nodes (lists, quote, code block, rule) survive rendering', function () {
    expect($this->html)
        ->toContain('<ul>')
        ->toContain('<ol>')
        ->toContain('<blockquote>')
        ->toContain('<pre><code>echo 1;')
        ->toContain('<hr>');
});

test('code block language attr survives rendering', function () {
    expect($this->html)->toContain('<code class="language-php">echo 2;');
});

test('task list nodes survive rendering', function () {
    expect($this->html)
        ->toContain('<ul data-type="taskList">')
        ->toContain('data-checked="true" data-type="taskItem"')
        ->toContain('data-checked="false" data-type="taskItem"')
        // Snapshot/compare HTML must not offer a live-looking checkbox.
        ->toContain('<input type="checkbox" disabled="disabled" checked="checked">')
        ->toContain('done task')
        ->toContain('open task');
});

test('callout node survives rendering', function () {
    expect($this->html)
        ->toContain('data-callout="warning"')
        ->toContain('class="callout callout-warning"')
        ->toContain('callout body');
});

test('text alignment survives rendering', function () {
    expect($this->html)->toContain('text-align: center');
});

test('resizable image node survives rendering', function () {
    expect($this->html)
        ->toContain('<img')
        ->toContain('src="/storage/assets/x.png"')
        ->toContain('width:200px');
});

test('wiki-link node survives rendering', function () {
    expect($this->html)
        ->toContain('class="wiki-link"')
        ->toContain('data-title="Other Page"')
        ->toContain('data-target-id="7"');
});

test('table nodes (row, header, cell) survive rendering', function () {
    expect($this->html)
        ->toContain('<table>')
        ->toContain('<th')
        ->toContain('data-colwidth="100"')
        ->toContain('background-color: #DAE6D4')
        ->toContain('<td>')
        ->toContain('H1')
        ->toContain('Cell');
});

test('network diagram node survives rendering', function () {
    expect($this->html)
        ->toContain('class="network-diagram-figure"')
        ->toContain('class="network-diagram"')
        ->toContain('My Net');            // name as caption
});

test('a malicious text colour is dropped, not injected into the style attribute', function () {
    // content JSON is user-supplied (the update endpoint takes an arbitrary doc)
    // and this HTML is rendered with dangerouslySetInnerHTML — so a colour value
    // must never carry extra CSS into the inline style.
    $html = RenderDocument::toHtml([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type'  => 'text',
                'text'  => 'x',
                'marks' => [[
                    'type'  => 'textStyle',
                    'attrs' => ['color' => 'red;position:fixed;inset:0;background:url(//evil)'],
                ]],
            ]],
        ]],
    ]);

    expect($html)
        ->not->toContain('position:fixed')
        ->not->toContain('url(//evil)')
        ->not->toContain('background');
});

test('valid colour literals still render', function () {
    $render = fn ($color) => RenderDocument::toHtml([
        'type' => 'doc',
        'content' => [[
            'type'    => 'paragraph',
            'content' => [[
                'type'  => 'text',
                'text'  => 'x',
                'marks' => [['type' => 'textStyle', 'attrs' => ['color' => $color]]],
            ]],
        ]],
    ]);

    expect($render('#ff0000'))->toContain('color: #ff0000');
    expect($render('rgb(1, 2, 3)'))->toContain('color: rgb(1, 2, 3)');
    expect($render('rebeccapurple'))->toContain('color: rebeccapurple');
});
