<?php

use App\Support\DiagramSvg;

test('it renders a basic node to an SVG string', function () {
    $graph = [
        'nodes' => [
            [
                'id' => 'n1',
                'type' => 'labeled',
                'position' => ['x' => 10, 'y' => 10],
                'data' => ['label' => 'Server', 'color' => 'blue']
            ]
        ],
        'edges' => []
    ];

    $result = DiagramSvg::render($graph);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['svg', 'width', 'height'])
        ->and($result['svg'])->toContain('<svg')
        ->and($result['svg'])->toContain('Server') // Label is rendered
        ->and($result['width'])->toBeGreaterThan(0)
        ->and($result['height'])->toBeGreaterThan(0);
});

test('it renders edges with routing', function () {
    $graph = [
        'nodes' => [
            ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'w' => 100, 'h' => 40],
            ['id' => 'n2', 'position' => ['x' => 0, 'y' => 100], 'w' => 100, 'h' => 40]
        ],
        'edges' => [
            [
                'source' => 'n1',
                'target' => 'n2',
                'data' => ['routing' => 'step']
            ]
        ]
    ];

    $result = DiagramSvg::render($graph);

    expect($result)->toBeArray()
        ->and($result['svg'])->toContain('<path d="M');
});

test('it handles empty graphs gracefully', function () {
    expect(DiagramSvg::render(null))->toBeNull();
    expect(DiagramSvg::render([]))->toBeNull();
    expect(DiagramSvg::render(['nodes' => []]))->toBeNull();
});

test('a non-hex edge colour is rejected, not interpolated into the SVG', function () {
    $graph = [
        'nodes' => [
            ['id' => 'n1', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'n2', 'position' => ['x' => 0, 'y' => 100]],
        ],
        'edges' => [
            [
                'source' => 'n1',
                'target' => 'n2',
                'data' => ['color' => '"><script>alert(1)</script>'],
            ],
        ],
    ];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->not->toContain('<script')
        ->and($svg)->toContain('stroke="#8E938E"'); // fell back to the default
});

test('a legacy multi-line label migrates to name + value-only property row (icon node)', function () {
    $graph = [
        'nodes' => [
            [
                'id' => 'n1',
                'type' => 'labeled',
                'position' => ['x' => 10, 'y' => 10],
                'width' => 160,
                'height' => 60,
                'data' => ['label' => "Server1\n10.10.10.10", 'color' => 'blue', 'kind' => 'server'],
            ],
        ],
        'edges' => [],
    ];

    $svg = DiagramSvg::render($graph)['svg'];

    // Legacy multi-line label -> name + one value-only property row (device card).
    expect($svg)->toContain('>Server1</text>')
        ->and($svg)->toContain('>10.10.10.10</text>')
        ->and($svg)->toContain('font-size="10"');
});

test('a legacy multi-line label migrates to name + value-only property row (generic node)', function () {
    $graph = [
        'nodes' => [
            [
                'id' => 'n1',
                'type' => 'labeled',
                'position' => ['x' => 0, 'y' => 0],
                'width' => 160,
                'height' => 60,
                'data' => ['label' => "Line A\nLine B", 'color' => 'default', 'kind' => 'generic'],
            ],
        ],
        'edges' => [],
    ];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->toContain('>Line A</text>')
        ->and($svg)->toContain('>Line B</text>')
        ->and($svg)->toContain('font-size="10"');
});

test('a single-line label still renders as one centered text element', function () {
    $graph = [
        'nodes' => [[
            'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
            'width' => 150, 'height' => 40, 'data' => ['label' => 'Solo', 'kind' => 'generic'],
        ]],
        'edges' => [],
    ];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->toContain('text-anchor="middle"')
        ->and($svg)->toContain('>Solo</text>')
        ->and($svg)->not->toContain('<tspan');
});

test('normalizeNode keeps a plain name and structured props', function () {
    $out = DiagramSvg::normalizeNode([
        'label' => 'Server1',
        'props' => [
            ['key' => 'IP', 'value' => '10.10.10.10'],
            ['key' => 'Role', 'value' => 'DB'],
        ],
    ]);

    expect($out['name'])->toBe('Server1')
        ->and($out['props'])->toBe([
            ['key' => 'IP', 'value' => '10.10.10.10'],
            ['key' => 'Role', 'value' => 'DB'],
        ]);
});

test('normalizeNode drops fully blank prop rows and trims', function () {
    $out = DiagramSvg::normalizeNode([
        'label' => '  Server1 ',
        'props' => [
            ['key' => ' IP ', 'value' => ' 10.0.0.1 '],
            ['key' => '', 'value' => ''],
            ['key' => 'Note', 'value' => ''],
        ],
    ]);

    expect($out['name'])->toBe('Server1')
        ->and($out['props'])->toBe([
            ['key' => 'IP', 'value' => '10.0.0.1'],
            ['key' => 'Note', 'value' => ''],
        ]);
});

test('normalizeNode migrates a legacy multi-line label to value-only props', function () {
    $out = DiagramSvg::normalizeNode(['label' => "Server1\n10.10.10.10\nprod"]);

    expect($out['name'])->toBe('Server1')
        ->and($out['props'])->toBe([
            ['key' => '', 'value' => '10.10.10.10'],
            ['key' => '', 'value' => 'prod'],
        ]);
});

test('normalizeNode does not migrate legacy lines when structured props exist', function () {
    $out = DiagramSvg::normalizeNode([
        'label' => "Server1\nignored-second-line",
        'props' => [['key' => 'IP', 'value' => '10.0.0.1']],
    ]);

    expect($out['name'])->toBe('Server1')
        ->and($out['props'])->toBe([['key' => 'IP', 'value' => '10.0.0.1']]);
});

test('a node with properties renders name plus key/value rows', function () {
    $graph = ['nodes' => [[
        'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
        'width' => 180, 'height' => 80,
        'data' => [
            'label' => 'Server1', 'kind' => 'server',
            'props' => [
                ['key' => 'IP', 'value' => '10.10.10.10'],
                ['key' => 'Role', 'value' => 'DB'],
            ],
        ],
    ]], 'edges' => []];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->toContain('>Server1</text>')       // name
        ->and($svg)->toContain('>IP</text>')          // key
        ->and($svg)->toContain('>10.10.10.10</text>') // value
        ->and($svg)->toContain('>Role</text>')
        ->and($svg)->toContain('>DB</text>')
        ->and($svg)->toContain('fill="#5C625C"')      // muted key colour used
        ->and($svg)->toContain('font-size="10"');     // property rows
});

test('a value-only property renders without a key', function () {
    $graph = ['nodes' => [[
        'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
        'width' => 160, 'height' => 60,
        'data' => ['label' => 'Server1', 'kind' => 'generic',
                   'props' => [['key' => '', 'value' => '10.10.10.10']]],
    ]], 'edges' => []];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->toContain('>Server1</text>')
        ->and($svg)->toContain('>10.10.10.10</text>');
});

test('a name-only node still renders one centered line', function () {
    $graph = ['nodes' => [[
        'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
        'width' => 150, 'height' => 40, 'data' => ['label' => 'Solo', 'kind' => 'generic'],
    ]], 'edges' => []];

    $svg = DiagramSvg::render($graph)['svg'];

    expect($svg)->toContain('text-anchor="middle"')
        ->and($svg)->toContain('>Solo</text>');
});

test('a long property key is truncated so it cannot overflow the card', function () {
    $graph = ['nodes' => [[
        'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
        'width' => 160, 'height' => 60,
        'data' => ['label' => 'Server1', 'kind' => 'generic',
                   'props' => [['key' => str_repeat('X', 60), 'value' => 'ok']]],
    ]], 'edges' => []];

    $svg = DiagramSvg::render($graph)['svg'];

    // The full 60-char key must not appear verbatim (it was truncated with …);
    // the value still renders.
    expect($svg)->not->toContain(str_repeat('X', 60))
        ->and($svg)->toContain('…')
        ->and($svg)->toContain('>ok</text>');
});

test('a device card with no persisted size grows to fit its property rows', function () {
    $graph = ['nodes' => [[
        'id' => 'n1', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
        // NO width/height => 150x40 fallback; must grow to fit 3 rows.
        'data' => ['label' => 'Server1', 'kind' => 'generic', 'props' => [
            ['key' => 'IP', 'value' => '10.10.10.10'],
            ['key' => 'Role', 'value' => 'DB'],
            ['key' => 'OS', 'value' => 'Debian 12'],
        ]],
    ]], 'edges' => []];

    $svg = DiagramSvg::render($graph)['svg'];

    // Node rects use rx="6"; the attribute order is width then height then rx.
    // The tallest such rect must enclose 3 rows (well past the 40px fallback).
    preg_match_all('/<rect[^>]*height="([\d.]+)"[^>]*rx="6"/', $svg, $m);
    $maxH = max(array_map('floatval', $m[1] ?: [0.0]));
    expect($maxH)->toBeGreaterThanOrEqual(69.0);
});
