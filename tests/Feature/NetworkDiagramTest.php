<?php

use App\Models\Document;
use App\Models\Workspace;
use App\Services\Exporters\DocxExporter;
use Database\Factories\DocumentFactory;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** A TipTap doc containing one networkDiagram block with the given graph + image. */
function diagramDoc(array $graph, ?string $imageSrc = null, string $name = ''): array
{
    return [
        'type'    => 'doc',
        'content' => [[
            'type'  => 'networkDiagram',
            'attrs' => ['graph' => $graph, 'imageSrc' => $imageSrc, 'name' => $name],
        ]],
    ];
}

/** A graph of labelled nodes (no edges/positions needed for these assertions). */
function graphWithLabels(array $labels): array
{
    $nodes = [];
    foreach ($labels as $i => $label) {
        $nodes[] = ['id' => "n{$i}", 'type' => 'labeled', 'position' => ['x' => $i * 100, 'y' => 0], 'data' => ['label' => $label]];
    }

    return ['nodes' => $nodes, 'edges' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]];
}

test('a document is found by a network diagram node label', function () {
    login();
    Document::factory()->create([
        'title'   => 'Datacenter Topology',
        'content' => diagramDoc(graphWithLabels(['core-router', 'edge-switch']), '/storage/assets/abc.png'),
    ]);
    Document::factory()->create([
        'title'   => 'Unrelated Page',
        'content' => DocumentFactory::tiptap('Nothing networky here.'),
    ]);

    // The label lives only in the diagram's graph attr; it reaches search through
    // the hidden-label text RenderDocument emits into content_html.
    $this->get('/search?q=core-router')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'Datacenter Topology')
    );
});

test('a multi-line node label is searchable line by line', function () {
    login();
    Document::factory()->create([
        'title'   => 'Server Room Layout',
        'content' => diagramDoc(graphWithLabels(["WebHost\n192.168.5.5", 'edge-switch']), '/storage/assets/abc.png'),
    ]);
    Document::factory()->create([
        'title'   => 'Unrelated Page Two',
        'content' => DocumentFactory::tiptap('Nothing networky here either.'),
    ]);

    // First line is its own token:
    $this->get('/search?q=WebHost')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'Server Room Layout')
    );

    // Second line (an IP) is its own token — newline acted as a separator, and
    // Task 2's fix makes the dotted IP query match:
    $this->get('/search?q=192.168.5.5')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'Server Room Layout')
    );
});

test('a diagram node is searchable by name, property value, and property key', function () {
    login();
    $graph = [
        'nodes' => [[
            'id' => 'n0', 'type' => 'labeled', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['label' => 'AppHost', 'kind' => 'server', 'props' => [
                ['key' => 'IP', 'value' => '172.16.9.9'],
                ['key' => 'Role', 'value' => 'API'],
            ]],
        ]],
        'edges'    => [],
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];

    Document::factory()->create([
        'title'   => 'App Infrastructure',
        'content' => diagramDoc($graph, '/storage/assets/abc.png'),
    ]);
    Document::factory()->create([
        'title'   => 'Unrelated Page Three',
        'content' => DocumentFactory::tiptap('Nothing networky here at all.'),
    ]);

    // Name:
    $this->get('/search?q=AppHost')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'App Infrastructure')
    );

    // Property value (an IP):
    $this->get('/search?q=172.16.9.9')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'App Infrastructure')
    );

    // Property key:
    $this->get('/search?q=Role')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.title', 'App Infrastructure')
    );
});

test('a document is found by its diagram name', function () {
    login();
    Document::factory()->create([
        'title'   => 'Branch Office',
        'content' => diagramDoc(graphWithLabels(['sw1']), '/storage/assets/abc.png', 'Warehouse Network'),
    ]);

    $this->get('/search?q=Warehouse')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Branch Office')
    );
});

test('the diagram name round-trips through a version snapshot', function () {
    login();
    $document = Document::factory()->create(['content' => DocumentFactory::tiptap('before')]);
    $document->update(['content' => diagramDoc(graphWithLabels(['n']), '/storage/assets/abc.png', 'Core Topology')]);

    $node = $document->versions()->latest('id')->first()->content['content'][0];
    expect($node['attrs']['name'])->toBe('Core Topology');
});

test('the DOCX export includes the diagram name as a caption', function () {
    Storage::fake('local');
    login();

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = storage_path('app/public/assets');
    @mkdir($dir, 0777, true);
    $abs = "{$dir}/diagram-named.png";
    file_put_contents($abs, $png);

    try {
        $document = Document::factory()->create([
            'content' => diagramDoc(graphWithLabels(['n']), '/storage/assets/diagram-named.png', 'Datacenter Rack'),
        ]);

        $path = (new DocxExporter)->export($document);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        expect($xml)->toContain('Datacenter Rack');
    } finally {
        @unlink($abs);
    }
});

test('the diagram graph round-trips through a version snapshot', function () {
    login();
    $document = Document::factory()->create([
        'content' => DocumentFactory::tiptap('Before the diagram.'),
    ]);

    $graph = graphWithLabels(['firewall', 'dmz']);
    $document->update(['content' => diagramDoc($graph, '/storage/assets/abc.png')]);

    // The newest version snapshots the exact content JSON, graph attr and all —
    // so restoring history reproduces the diagram, not just its image.
    $version  = $document->versions()->latest('id')->first();
    $snapNode = $version->content['content'][0];

    expect($snapNode['type'])->toBe('networkDiagram')
        ->and($snapNode['attrs']['graph']['nodes'])->toHaveCount(2)
        ->and($snapNode['attrs']['graph']['nodes'][0]['data']['label'])->toBe('firewall')
        ->and($snapNode['attrs']['imageSrc'])->toBe('/storage/assets/abc.png');
});

test('the DOCX export embeds the diagram PNG', function () {
    Storage::fake('local');
    login();

    // addImage reads the real filesystem (storage/app/public), not a faked disk —
    // drop a genuine 1x1 PNG there for it to embed, then clean up.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = storage_path('app/public/assets');
    @mkdir($dir, 0777, true);
    $abs = "{$dir}/diagram-test.png";
    file_put_contents($abs, $png);

    try {
        $document = Document::factory()->create([
            'content' => diagramDoc(graphWithLabels(['router']), '/storage/assets/diagram-test.png'),
        ]);

        $path = (new DocxExporter)->export($document);

        // A DOCX is a zip; an embedded image lands under word/media/.
        $zip = new ZipArchive;
        expect($zip->open(Storage::disk('local')->path($path)))->toBeTrue();

        $hasMedia = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'word/media/')) {
                $hasMedia = true;
                break;
            }
        }
        $zip->close();

        expect($hasMedia)->toBeTrue();
    } finally {
        @unlink($abs);
    }
});

test('a diagram with no captured imageSrc still exports media rendered from its graph', function () {
    Storage::fake('local');
    login();

    // No client-captured PNG (imageSrc null): the diagram is rendered server-side
    // from its canonical graph (SVG + PNG fallback), so the export never goes
    // blank — media still lands under word/media/.
    $document = Document::factory()->create([
        'content' => diagramDoc(graphWithLabels(['router']), null),
    ]);

    $path = (new DocxExporter)->export($document);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $hasMedia = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'word/media/')) {
            $hasMedia = true;
            break;
        }
    }
    $zip->close();

    expect($hasMedia)->toBeTrue();
});

test('the DOCX export scales the diagram to the page content width', function () {
    Storage::fake('local');
    login();

    $document = Document::factory()->create([
        'content' => diagramDoc(graphWithLabels(['router']), null),
    ]);

    $path = (new DocxExporter)->export($document);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    // The drawing width is scaled to the ~6in content-width target (5486400 EMU),
    // not the graph's small intrinsic px width — so the diagram fills the page.
    expect($xml)->toContain('cx="5486400"');
});
