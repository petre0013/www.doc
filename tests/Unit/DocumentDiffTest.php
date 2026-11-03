<?php

use App\Support\DocumentDiff;

/** Shorthand: a doc payload for DocumentDiff::compare. */
function diffSide(string $title = 'Page', ?array $content = null, array $tags = []): array
{
    return ['title' => $title, 'content' => $content, 'tags' => $tags];
}

function paragraphDoc(string ...$sentences): array
{
    return ['type' => 'doc', 'content' => array_map(
        fn ($s) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $s]]],
        $sentences,
    )];
}

function diffDiagramDoc(array $graph, string $name = 'Net'): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'intro']]],
        ['type' => 'networkDiagram', 'attrs' => ['name' => $name, 'graph' => $graph]],
    ]];
}

function nd(string $id, string $label, array $extra = []): array
{
    return array_merge(['id' => $id, 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $label]], $extra);
}

// ── Body / title / tags ─────────────────────────────────────────────────────

test('word-level body diff wraps changes in ins and del', function () {
    $diff = DocumentDiff::compare(
        diffSide(content: paragraphDoc('The quick brown fox jumps.')),
        diffSide(content: paragraphDoc('The quick red fox leaps.')),
    );

    expect($diff['body']['changed'])->toBeTrue()
        ->and($diff['body']['skipped'])->toBeFalse()
        ->and($diff['body']['html'])->toContain('<ins')->toContain('red')
        ->and($diff['body']['html'])->toContain('<del')->toContain('brown');
});

test('a colour-only change is flagged formatting_only', function () {
    $colored = fn (string $hex) => ['type' => 'doc', 'content' => [[
        'type'    => 'paragraph',
        'content' => [[
            'type'  => 'text',
            'text'  => 'important note',
            'marks' => [['type' => 'textStyle', 'attrs' => ['color' => $hex]]],
        ]],
    ]]];

    $diff = DocumentDiff::compare(
        diffSide(content: $colored('#b5573e')),
        diffSide(content: $colored('#4b6840')),
    );

    // htmldiff can't mark attribute-only edits — the body counts as changed
    // but carries zero <ins>/<del> markers, hence the flag.
    expect($diff['identical'])->toBeFalse()
        ->and($diff['body']['changed'])->toBeTrue()
        ->and($diff['body']['formatting_only'])->toBeTrue()
        ->and($diff['body']['html'])->not->toContain('<ins')
        ->and($diff['body']['html'])->not->toContain('<del');

    // A real text edit must never be labelled formatting-only.
    $diff = DocumentDiff::compare(
        diffSide(content: paragraphDoc('The quick brown fox.')),
        diffSide(content: paragraphDoc('The quick red fox.')),
    );

    expect($diff['body']['formatting_only'])->toBeFalse();
});

test('identical payloads short-circuit as identical', function () {
    $side = diffSide('Same', paragraphDoc('unchanged text'), ['ops']);
    $diff = DocumentDiff::compare($side, $side);

    expect($diff['identical'])->toBeTrue()
        ->and($diff['body']['changed'])->toBeFalse()
        ->and($diff['title']['changed'])->toBeFalse()
        ->and($diff['tags'])->toBe(['added' => [], 'removed' => []])
        ->and($diff['blocks'])->toBe([])
        ->and($diff['diagrams'])->toBe([]);
});

test('null content on one side reports a full insertion without throwing', function () {
    $diff = DocumentDiff::compare(
        diffSide(content: null),
        diffSide(content: paragraphDoc('brand new body')),
    );

    expect($diff['body']['changed'])->toBeTrue()
        ->and($diff['body']['html'])->toContain('<ins')->toContain('brand new body');
});

test('title and tag diffs report old/new and added/removed sets', function () {
    $diff = DocumentDiff::compare(
        diffSide('Old Title', null, ['a', 'b']),
        diffSide('New Title', null, ['b', 'c']),
    );

    expect($diff['title'])->toBe(['changed' => true, 'old' => 'Old Title', 'new' => 'New Title'])
        ->and($diff['tags'])->toBe(['added' => ['c'], 'removed' => ['a']]);
});

test('unchanged list items with wiki-links are not marked when something else changes', function () {
    // Regression: caxy's list differ reads TipTap's <li><p>…</p> items as
    // empty text (its tag whitelist lacks <p>), so identical items came back
    // removed + re-added. Lists are diffed inline now.
    $listItem = fn (string $text, string $link) => ['type' => 'listItem', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'wikiLink', 'attrs' => ['title' => $link, 'target_id' => 3]],
        ]],
    ]];
    $doc = fn (string $tail) => ['type' => 'doc', 'content' => [
        ['type' => 'bulletList', 'content' => [
            $listItem('PHP — ', 'PHP Style'),
            $listItem('React — ', 'React Style'),
        ]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $tail]]],
    ]];

    $diff = DocumentDiff::compare(
        diffSide(content: $doc('original closing line')),
        diffSide(content: $doc('edited closing line')),
    );

    // The list is untouched: no ins/del anywhere before it ends.
    [$listPart] = explode('</ul>', $diff['body']['html'], 2);
    expect($listPart)->not->toContain('<ins')->not->toContain('<del')
        ->and($diff['body']['html'])->toContain('<del class="diffmod">original</del>');

    // And a genuinely edited item still diffs word-level inside the list.
    $changed = DocumentDiff::compare(
        diffSide(content: $doc('same tail')),
        diffSide(content: array_replace($doc('same tail'), ['content' => [
            ['type' => 'bulletList', 'content' => [
                $listItem('PHP — ', 'PHP Style'),
                $listItem('Vue — ', 'React Style'),
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'same tail']]],
        ]])),
    );
    expect($changed['body']['html'])->toContain('<del')->toContain('React')
        ->and($changed['body']['html'])->toContain('<ins')->toContain('Vue');
});

// ── Blocks ──────────────────────────────────────────────────────────────────

test('an image src swap is one modified block and a clean atomic img diff', function () {
    $img = fn (string $src) => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
        ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => 'screenshot']],
    ]];

    $diff = DocumentDiff::compare(
        diffSide(content: $img('/storage/assets/old.png')),
        diffSide(content: $img('/storage/assets/new.png')),
    );

    expect($diff['blocks'])->toHaveCount(1)
        ->and($diff['blocks'][0])->toMatchArray(['type' => 'image', 'status' => 'modified'])
        // Canary for caxy's <img> token handling: the swap must appear as one
        // whole-tag del + one whole-tag ins, never spliced attributes.
        ->and(substr_count($diff['body']['html'], '<img'))->toBe(2)
        ->and($diff['body']['html'])->toContain('old.png')->toContain('new.png');
});

test('table add and cell-change are reported as coarse block entries', function () {
    $table = fn (string $cell) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $cell]]]]],
    ]]]];

    $added = DocumentDiff::compare(
        diffSide(content: paragraphDoc('x')),
        diffSide(content: ['type' => 'doc', 'content' => [$table('Cell')]]),
    );
    expect($added['blocks'])->toHaveCount(1)
        ->and($added['blocks'][0])->toMatchArray(['type' => 'table', 'status' => 'added']);

    $modified = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [$table('Old cell')]]),
        diffSide(content: ['type' => 'doc', 'content' => [$table('New cell')]]),
    );
    expect($modified['blocks'][0])->toMatchArray(['type' => 'table', 'status' => 'modified']);
});

test('table diffing with ampersands does not throw an ErrorException', function () {
    // Regression: vendor TableDiff::diffCells un-escaped entities ("&amp;" → bare
    // "&"), triggering a DOMDocument::loadHTML warning that Laravel promotes to
    // an ErrorException. Fixed at the source in Utf8TableDiff.
    $table = fn (string $cell) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $cell]]]]],
    ]]]];

    $diff = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [$table('Me & You')]]),
        diffSide(content: ['type' => 'doc', 'content' => [$table('Me & You later')]]),
    );

    expect($diff['body']['html'])->toContain('later')
        ->toContain('Me &amp; You');
});

test('table diffing preserves emoji', function () {
    $table = fn (string $cell) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $cell]]]]],
    ]]]];

    $diff = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [$table('Rocket 🚀')]]),
        diffSide(content: ['type' => 'doc', 'content' => [$table('Rocket 🚀 later')]]),
    );

    expect($diff['body']['html'])->toContain('🚀');
});

test('special characters survive in added table rows', function () {
    // Added/removed cells take the null-cell branch of diffCells — exercise it too.
    $row = fn (string $cell) => ['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $cell]]]]],
    ]];

    $diff = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [$row('base')]]]]),
        diffSide(content: ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [$row('base'), $row('R&D 🚀')]]]]),
    );

    expect($diff['body']['html'])->toContain('R&amp;D')
        ->toContain('🚀');
});

test('plain prose with ampersands and emoji diffs cleanly', function () {
    // The numeric-entity decode pass runs over the WHOLE diff output — make
    // sure non-table prose keeps its escaping and its emoji too.
    $para = fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];

    $diff = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [$para('Tom & Jerry 🎉')]]),
        diffSide(content: ['type' => 'doc', 'content' => [$para('Tom & Jerry 🎉 forever')]]),
    );

    expect($diff['body']['html'])->toContain('Tom &amp; Jerry')
        ->toContain('🎉')
        ->toContain('<ins');
});

test('table diffing keeps literal markup in cell text escaped', function () {
    // Regression (stored XSS): vendor TableDiff::diffCells ran cell HTML through
    // mb_convert_encoding(…, 'HTML-ENTITIES'), un-escaping user text one level —
    // "<img onerror=…>" typed as TEXT came back as a live element in the diff.
    $table = fn (string $cell) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $cell]]]]],
    ]]]];

    $diff = DocumentDiff::compare(
        diffSide(content: ['type' => 'doc', 'content' => [$table('hi <img src=x onerror=alert(1)>')]]),
        diffSide(content: ['type' => 'doc', 'content' => [$table('hi <img src=x onerror=alert(1)> later')]]),
    );

    expect($diff['body']['html'])
        ->toContain('&lt;img')
        ->not->toContain('<img');
});

// ── Diagrams ────────────────────────────────────────────────────────────────

test('diagram node changes are detected per kind', function () {
    $old = ['nodes' => [
        nd('a', 'router'),
        nd('b', 'switch', ['width' => 150]),
        nd('c', 'firewall', ['data' => ['label' => 'firewall', 'color' => 'sage', 'kind' => 'server']]),
    ], 'edges' => []];

    $new = ['nodes' => [
        nd('a', 'core-router'),                                                            // renamed
        nd('b', 'switch', ['width' => 220]),                                               // resized
        nd('c', 'firewall', ['data' => ['label' => 'firewall', 'color' => 'blue', 'kind' => 'router']]), // recolored + retyped
        nd('d', 'new-node'),                                                               // added
    ], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($old)),
        diffSide(content: diffDiagramDoc($new)),
    );

    $kinds = array_column($diff['diagrams'][0]['changes'], 'kind');
    expect($diff['diagrams'][0]['status'])->toBe('modified')
        ->and($kinds)->toContain('node-renamed', 'node-resized', 'node-recolored', 'node-retyped', 'node-added');
});

test('edges are keyed by id with endpoint fallback, and reroutes are detected', function () {
    $nodes = [nd('a', 'A'), nd('b', 'B'), nd('c', 'C')];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc(['nodes' => $nodes, 'edges' => [
            ['id' => 'e1', 'source' => 'a', 'target' => 'b'],
            ['source' => 'b', 'target' => 'c'], // id-less: keyed by endpoints
        ]])),
        diffSide(content: diffDiagramDoc(['nodes' => $nodes, 'edges' => [
            ['id' => 'e1', 'source' => 'a', 'target' => 'c'], // rerouted
            // b→c edge gone → removed
        ]])),
    );

    $kinds = array_column($diff['diagrams'][0]['changes'], 'kind');
    expect($kinds)->toContain('edge-rerouted', 'edge-removed');
});

test('position-only moves are bucketed, never listed as changes', function () {
    $old = ['nodes' => [nd('a', 'A'), nd('b', 'B'), nd('c', 'C')], 'edges' => []];
    $new = $old;
    foreach ($new['nodes'] as $i => $node) {
        $new['nodes'][$i]['position'] = ['x' => 100 + $i, 'y' => 50];
    }

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($old)),
        diffSide(content: diffDiagramDoc($new)),
    );

    expect($diff['diagrams'][0]['changes'])->toBe([])
        ->and($diff['diagrams'][0]['repositioned'])->toBe(3)
        ->and($diff['diagrams'][0]['status'])->toBe('unchanged');
});

test('the merged overlay graph carries status colors and dashed removed edges', function () {
    $old = ['nodes' => [nd('a', 'A'), nd('b', 'B')], 'edges' => [['id' => 'e1', 'source' => 'a', 'target' => 'b']]];
    $new = ['nodes' => [nd('a', 'A-renamed'), nd('c', 'C')], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($old)),
        diffSide(content: diffDiagramDoc($new)),
    );

    $overlay = $diff['diagrams'][0]['overlay_graph'];
    $colorOf = fn (string $id) => collect($overlay['nodes'])->firstWhere('id', $id)['data']['color'] ?? null;

    expect($colorOf('a'))->toBe('#C99650')   // modified (renamed)
        ->and($colorOf('c'))->toBe('#4B6840') // added
        ->and($colorOf('b'))->toBe('#B5573E') // removed ghost at old position
        ->and($overlay['edges'][0]['data'])->toMatchArray(['color' => '#B5573E', 'lineStyle' => 'dashed']);

    // The whole overlay renders through the EXISTING DiagramSvg untouched.
    $svg = \App\Support\DiagramSvg::render($overlay);
    expect($svg['svg'])->toContain('stroke-dasharray');
});

test('a whole added or removed diagram becomes a tinted entry', function () {
    $graph = ['nodes' => [nd('a', 'A')], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: paragraphDoc('no diagram')),
        diffSide(content: diffDiagramDoc($graph, 'New Net')),
    );

    expect($diff['diagrams'])->toHaveCount(1)
        ->and($diff['diagrams'][0]['status'])->toBe('added')
        ->and($diff['diagrams'][0]['overlay_graph']['nodes'][0]['data']['color'])->toBe('#4B6840');
});

test('a node property edit is a semantic change, not silence', function () {
    // props hold the node's real data (IPs, models) — an edit there used to
    // leave the diagram "unchanged", so the compare page showed nothing at all.
    $withProps = fn (array $props) => ['nodes' => [
        nd('a', 'core-router', ['data' => ['label' => 'core-router', 'props' => $props]]),
    ], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($withProps([['key' => 'IP', 'value' => '192.0.2.10']]))),
        diffSide(content: diffDiagramDoc($withProps([['key' => 'IP', 'value' => '192.0.2.99']]))),
    );

    $entry = $diff['diagrams'][0];
    expect($entry['status'])->toBe('modified')
        ->and(array_column($entry['changes'], 'kind'))->toContain('node-prop-changed')
        ->and($entry['changes'][0]['text'])->toContain('IP')
        ->and($entry['changes'][0]['text'])->toContain('192.0.2.10')
        ->and($entry['changes'][0]['text'])->toContain('192.0.2.99')
        // and the node is tinted in the overlay like any other modification
        ->and($entry['overlay_graph']['nodes'][0]['data']['color'])->toBe('#C99650');
});

test('adding or removing a node property reports one collapsed change', function () {
    $withProps = fn (array $props) => ['nodes' => [
        nd('a', 'core-router', ['data' => ['label' => 'core-router', 'props' => $props]]),
    ], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($withProps([]))),
        diffSide(content: diffDiagramDoc($withProps([['key' => 'IP', 'value' => '192.0.2.10']]))),
    );

    expect($diff['diagrams'][0]['status'])->toBe('modified')
        ->and(array_column($diff['diagrams'][0]['changes'], 'kind'))->toBe(['node-props-changed']);
});

test('legacy value-only props still report a change', function () {
    // DiagramSvg::normalizeNode tolerates prop entries that are not {key,value}
    // arrays, so graphs carrying them exist. Reading ['key'] off those yields
    // null on BOTH sides — the per-index compare would call them equal and the
    // diagram would read "unchanged" while its data changed.
    $withProps = fn (array $props) => ['nodes' => [
        nd('a', 'core-router', ['data' => ['label' => 'core-router', 'props' => $props]]),
    ], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($withProps(['192.0.2.10', '192.0.2.20']))),
        diffSide(content: diffDiagramDoc($withProps(['192.0.2.99', '192.0.2.20']))),
    );

    expect($diff['diagrams'][0]['status'])->toBe('modified')
        ->and(array_column($diff['diagrams'][0]['changes'], 'kind'))->toBe(['node-props-changed']);
});

test('renaming a diagram is reported even though its graph is untouched', function () {
    $graph = ['nodes' => [nd('a', 'A')], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($graph, 'Rack A')),
        diffSide(content: diffDiagramDoc($graph, 'Rack B')),
    );

    expect($diff['diagrams'][0]['status'])->toBe('modified')
        ->and($diff['diagrams'][0]['changes'][0]['kind'])->toBe('diagram-renamed')
        ->and($diff['diagrams'][0]['changes'][0]['text'])->toContain('Rack A')
        ->and($diff['diagrams'][0]['changes'][0]['text'])->toContain('Rack B');
});

test('diagrams are stripped from the body diff', function () {
    $graph = ['nodes' => [nd('a', 'router-x')], 'edges' => []];

    $diff = DocumentDiff::compare(
        diffSide(content: diffDiagramDoc($graph)),
        diffSide(content: array_replace(diffDiagramDoc($graph), ['content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'intro edited']]],
            ['type' => 'networkDiagram', 'attrs' => ['name' => 'Net', 'graph' => $graph]],
        ]])),
    );

    expect($diff['body']['html'])->not->toContain('network-diagram')
        ->and($diff['body']['html'])->not->toContain('data:image');
});

// ── Size cap ────────────────────────────────────────────────────────────────

test('a page too large for the inline differ falls back instead of timing out', function () {
    // php-htmldiff is ~cubic: past the cap a real request would exceed FPM's
    // 30s limit. The whole point of the cap is that this returns immediately.
    $long = fn (string $tail) => paragraphDoc(...array_map(
        fn ($i) => str_repeat("network switch vlan record $i ", 30) . $tail,
        range(1, 60),
    ));

    $started = microtime(true);
    $diff = DocumentDiff::compare(
        diffSide(content: $long('')),
        diffSide(content: $long('edited')),
    );

    expect(strlen($diff['body']['leftHtml']))->toBeGreaterThan(25_000)
        ->and($diff['body']['skipped'])->toBeTrue()
        ->and($diff['body']['changed'])->toBeTrue()
        // the side-by-side fallback still needs both plain renders
        ->and($diff['body']['rightHtml'])->toContain('edited')
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

test('an ordinary page still gets the inline diff', function () {
    $page = fn (string $tail) => paragraphDoc(...array_map(
        fn ($i) => str_repeat("network switch vlan record $i ", 15) . $tail,
        range(1, 10),
    ));

    $diff = DocumentDiff::compare(
        diffSide(content: $page('')),
        diffSide(content: $page('edited')),
    );

    expect($diff['body']['skipped'])->toBeFalse()
        ->and($diff['body']['html'])->toContain('<ins');
});

// ── Schema parity + fail-loud guard ─────────────────────────────────────────

test('the differ understands every node in the schema fixture', function () {
    $edited = fixtureDoc();
    $edited['content'][1]['content'][0]['text'] = 'bolder '; // small text edit

    $diff = DocumentDiff::compare(
        diffSide('T', fixtureDoc(), []),
        diffSide('T', $edited, []),
    );

    expect($diff['body']['changed'])->toBeTrue();
});

test('an unknown node type fails loudly', function () {
    $doc = ['type' => 'doc', 'content' => [['type' => 'someFutureNode']]];

    DocumentDiff::compare(diffSide(content: $doc), diffSide(content: $doc));
})->throws(RuntimeException::class, 'someFutureNode');

// ── summarize() ─────────────────────────────────────────────────────────────

test('summarize counts word and block deltas and flags diagram changes', function () {
    $summary = DocumentDiff::summarize(
        diffSide(content: paragraphDoc('alpha beta gamma')),
        diffSide(content: ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'alpha delta']]],
            ['type' => 'image', 'attrs' => ['src' => '/storage/assets/pic.png']],
        ]]),
    );

    expect($summary['words_added'])->toBe(1)      // delta
        ->and($summary['words_removed'])->toBe(2) // beta gamma
        ->and($summary['blocks_added'])->toBe(1)
        ->and($summary['diagram_changed'])->toBeFalse()
        ->and($summary['formatting_changed'])->toBeFalse();

    $dragged = diffDiagramDoc(['nodes' => [nd('a', 'A', ['position' => ['x' => 9, 'y' => 9]])], 'edges' => []]);
    $summary = DocumentDiff::summarize(
        diffSide(content: diffDiagramDoc(['nodes' => [nd('a', 'A')], 'edges' => []])),
        diffSide(content: $dragged),
    );

    expect($summary['diagram_changed'])->toBeTrue();
});

test('summarize flags formatting-only edits, and only those', function () {
    $colored = fn (string $hex) => ['type' => 'doc', 'content' => [[
        'type'    => 'paragraph',
        'content' => [[
            'type'  => 'text',
            'text'  => 'important note',
            'marks' => [['type' => 'textStyle', 'attrs' => ['color' => $hex]]],
        ]],
    ]]];

    // Colour-only edit: no word/block/diagram signal, content differs.
    $summary = DocumentDiff::summarize(
        diffSide(content: $colored('#b5573e')),
        diffSide(content: $colored('#4b6840')),
    );

    expect($summary['words_added'])->toBe(0)
        ->and($summary['words_removed'])->toBe(0)
        ->and($summary['formatting_changed'])->toBeTrue();

    // Unchanged content (title-only edit) must not claim formatting changed.
    $summary = DocumentDiff::summarize(
        diffSide('Old title', $colored('#b5573e')),
        diffSide('New title', $colored('#b5573e')),
    );

    expect($summary['formatting_changed'])->toBeFalse();
});
