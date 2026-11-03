<?php

use App\Services\RenderDocument;

/** Build a one-node doc wrapping the given node. */
function docWith(array $node): array
{
    return ['type' => 'doc', 'content' => [$node]];
}

test('a network diagram renders its graph as an inline SVG image', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'graph'    => ['nodes' => [
                ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'core-router']],
            ], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]],
            'imageSrc' => '/storage/assets/abc123.png',
            'align'    => 'center',
        ],
    ]));

    // The image is rendered from the canonical graph as an inline SVG data-URI —
    // not the (legacy) client-captured imageSrc PNG, which is ignored here.
    expect($html)
        ->toContain('<img')
        ->toContain('src="data:image/svg+xml;base64,')
        ->toContain('class="network-diagram"')
        ->toContain('margin:0 auto;')
        ->not->toContain('/storage/assets/abc123.png');
});

test('a network diagram with an empty graph falls back to a placeholder', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => ['graph' => ['nodes' => [], 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]],
    ]));

    expect($html)
        ->toContain('network-diagram-placeholder')
        ->not->toContain('<img');
});

test('the graph plumbing never leaks into the rendered HTML', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'graph'    => [
                'nodes' => [['id' => 'n1', 'position' => ['x' => 42, 'y' => 99], 'data' => ['label' => 'core-router']]],
                'edges' => [['id' => 'e1', 'source' => 'n1', 'target' => 'n2']],
                'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            ],
            'imageSrc' => '/storage/assets/abc123.png',
        ],
    ]));

    // Structural graph data (ids, coordinates, edges, the attr itself) is for the
    // editor/PNG pipeline, never markup.
    expect($html)
        ->not->toContain('"id"')
        ->not->toContain('viewport')
        ->not->toContain('"x":42')
        ->not->toContain('"source"')
        ->not->toContain('graph');
});

test('node labels are emitted as hidden text so full-text search can index them', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'graph'    => ['nodes' => [
                ['id' => 'n1', 'data' => ['label' => 'core-router']],
                ['id' => 'n2', 'data' => ['label' => 'edge-switch']],
            ], 'edges' => []],
            'imageSrc' => '/storage/assets/abc123.png',
        ],
    ]));

    // Labels are present (searchable) but hidden — they ride alongside the PNG,
    // not in place of it, and don't show in the read view / PDF.
    expect($html)
        ->toContain('network-diagram-labels')
        ->toContain('core-router')
        ->toContain('edge-switch')
        ->toContain('<img');

    // Stripping tags (how the search vector is built) surfaces the label text.
    expect(strip_tags($html))->toContain('core-router')->toContain('edge-switch');
});

test('a named diagram renders the name as a caption and the image alt', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'name'     => 'Office LAN',
            'graph'    => ['nodes' => [
                ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'fw']],
            ], 'edges' => []],
            'imageSrc' => '/storage/assets/abc123.png',
        ],
    ]));

    expect($html)
        ->toContain('<figcaption')
        ->toContain('Office LAN')
        ->toContain('alt="Office LAN"');

    // The caption is visible text, so it lands in the search vector too.
    expect(strip_tags($html))->toContain('Office LAN');
});

test('an unnamed diagram captions and alts as "Untitled diagram"', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'graph'    => ['nodes' => [
                ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'host']],
            ], 'edges' => []],
            'imageSrc' => '/storage/assets/abc123.png',
        ],
    ]));

    expect($html)
        ->toContain('<figcaption')
        ->toContain('Untitled diagram')
        ->toContain('alt="Untitled diagram"');
});

test('a diagram with no labels emits no hidden label span', function () {
    $html = RenderDocument::toHtml(docWith([
        'type'  => 'networkDiagram',
        'attrs' => [
            'graph'    => ['nodes' => [], 'edges' => []],
            'imageSrc' => '/storage/assets/abc123.png',
        ],
    ]));

    expect($html)->not->toContain('network-diagram-labels');
});

test('a diagram node sitting next to text does not disturb the surrounding content', function () {
    $html = RenderDocument::toHtml([
        'type'    => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Topology below.']]],
            ['type' => 'networkDiagram', 'attrs' => [
                'graph'    => ['nodes' => [
                    ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'core']],
                ], 'edges' => []],
                'imageSrc' => '/storage/assets/abc123.png',
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Notes above.']]],
        ],
    ]);

    expect($html)
        ->toContain('Topology below.')
        ->toContain('Notes above.')
        ->toContain('class="network-diagram"');
});

test('images never emit the legacy align attribute (it floats them out of flow)', function () {
    // `align="left|right"` is translated to `float:…` by browser UA stylesheets
    // AND by Dompdf, so images stop stacking and pile up side by side on the
    // Compare page and in PDFs. Alignment must travel via inline style only.
    foreach (['left', 'center', 'right'] as $align) {
        $html = RenderDocument::toHtml(docWith([
            'type'  => 'image',
            'attrs' => ['src' => '/storage/a.png', 'alt' => '', 'align' => $align],
        ]));

        expect($html)
            ->toContain('display:block;')
            ->toContain('data-align="' . $align . '"')
            ->not->toMatch('/<img[^>]*\salign=/');
    }
});

test('fromHtml parses HTML into a TipTap JSON structure', function () {
    $html = '<h1>Main Heading</h1><p>This is <strong>bold</strong> and <em>italic</em> text.</p>';
    
    $doc = RenderDocument::fromHtml($html);
    
    expect($doc)->toBeArray()
        ->and($doc['type'])->toBe('doc')
        ->and($doc['content'])->toHaveCount(2)
        ->and($doc['content'][0]['type'])->toBe('heading')
        ->and($doc['content'][0]['attrs']['level'])->toBe(1)
        ->and($doc['content'][1]['type'])->toBe('paragraph');
        
    $textNodes = $doc['content'][1]['content'];
    expect($textNodes)->toHaveCount(5)
        ->and($textNodes[0]['text'])->toBe('This is ')
        ->and($textNodes[1]['marks'][0]['type'])->toBe('bold')
        ->and($textNodes[1]['text'])->toBe('bold');
});

test('resolveImageToDataUri embeds an image that lives on the public disk', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = storage_path('app/public/assets');
    @mkdir($dir, 0777, true);
    $abs = "{$dir}/embed-test.png";
    file_put_contents($abs, $png);

    try {
        expect(RenderDocument::resolveImageToDataUri('/storage/assets/embed-test.png'))
            ->toStartWith('data:image/');
    } finally {
        @unlink($abs);
    }
});

test('resolveImageToDataUri refuses a /storage path that escapes the public disk', function () {
    // A ../ traversal out of storage/app/public must not be read — it returns
    // the neutral placeholder instead of the file's bytes.
    expect(RenderDocument::resolveImageToDataUri('/storage/../private/secret.txt'))
        ->toBe(RenderDocument::UNAVAILABLE_IMAGE);
});

test('resolveImageToDataUri refuses a private/link-local host (ssrf guard)', function () {
    // Cloud metadata endpoint — the guard rejects it, so export never fetches it.
    expect(RenderDocument::resolveImageToDataUri('http://169.254.169.254/latest/meta-data/'))
        ->toBe(RenderDocument::UNAVAILABLE_IMAGE);
});

test('resolveImageToDataUri follows a redirect (many image hosts 302 to a CDN)', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    // Public IP literals so the SSRF guard validates each hop without DNS.
    Illuminate\Support\Facades\Http::fake([
        '1.1.1.1/*' => Illuminate\Support\Facades\Http::response('', 302, ['Location' => 'http://8.8.8.8/real.png']),
        '8.8.8.8/*' => Illuminate\Support\Facades\Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    expect(RenderDocument::resolveImageToDataUri('http://1.1.1.1/img'))
        ->toStartWith('data:image/');
});

test('resolveImageToDataUri blocks a redirect that points at a private host', function () {
    // The per-hop SSRF re-validation must reject a 302 → link-local bounce; the
    // metadata stub must never be embedded.
    Illuminate\Support\Facades\Http::fake([
        '1.1.1.1/*'         => Illuminate\Support\Facades\Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Illuminate\Support\Facades\Http::response('secret', 200, ['Content-Type' => 'image/png']),
    ]);

    expect(RenderDocument::resolveImageToDataUri('http://1.1.1.1/img'))
        ->toBe(RenderDocument::UNAVAILABLE_IMAGE);
});
