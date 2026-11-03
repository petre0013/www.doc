<?php

use App\Services\Importers\DocxImporter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/** Build a .docx on disk with headings + underline/bold/italic and return its path. */
function makeFormattedDocx(): string
{
    $pw = new PhpWord();
    $pw->addTitleStyle(1, ['size' => 18, 'bold' => true]);
    $pw->addTitleStyle(2, ['size' => 14, 'bold' => true]);

    $section = $pw->addSection();
    $section->addTitle('Main Heading', 1);
    $section->addTitle('Sub Heading', 2);

    $run = $section->addTextRun();
    $run->addText('This is ');
    $run->addText('underlined', ['underline' => 'single']);
    $run->addText(', ');
    $run->addText('bold', ['bold' => true]);
    $run->addText(', and ');
    $run->addText('italic', ['italic' => true]);
    $run->addText(' text.');

    $path = tempnam(sys_get_temp_dir(), 'import') . '.docx';
    IOFactory::createWriter($pw, 'Word2007')->save($path);

    return $path;
}

/** Recursively collect every node of a given type from a TipTap doc. */
function collectNodes(array $node, string $type): array
{
    $found = ($node['type'] ?? null) === $type ? [$node] : [];

    foreach ($node['content'] ?? [] as $child) {
        $found = array_merge($found, collectNodes($child, $type));
    }

    return $found;
}

test('docx import preserves heading levels', function () {
    $path = makeFormattedDocx();

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    $headings = collectNodes($result['content'], 'heading');

    expect($headings)->toHaveCount(2);
    expect($headings[0]['attrs']['level'])->toBe(1);
    expect($headings[1]['attrs']['level'])->toBe(2);
});

test('docx import recognises numbered headings', function () {
    // A PhpWord heading has no numbering; inject a <w:numPr> to mimic a numbered
    // heading from Word. PhpWord's reader would treat that as a list item and
    // drop the heading level — the importer must strip the numbering first.
    $path = makeFormattedDocx();

    $zip = new \ZipArchive();
    $zip->open($path);
    $xml = $zip->getFromName('word/document.xml');
    $xml = preg_replace(
        '#(<w:pStyle w:val="Heading1"\s*/>)#',
        '$1<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>',
        $xml,
        1
    );
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    $levels = collect(collectNodes($result['content'], 'heading'))->pluck('attrs.level');
    expect($levels)->toContain(1);
});

test('docx import preserves the underline mark', function () {
    $path = makeFormattedDocx();

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    $marked = collect(collectNodes($result['content'], 'text'))
        ->mapWithKeys(fn ($n) => [
            $n['text'] => collect($n['marks'] ?? [])->pluck('type')->all(),
        ]);

    // The stray whitespace PhpWord emits (`underline ;`) must not drop the mark.
    expect($marked['underlined'])->toContain('underline');
    expect($marked['bold'])->toContain('bold');
    expect($marked['italic'])->toContain('italic');
});

test('docx import maps bulleted and numbered lists, with nesting', function () {
    $pw = new PhpWord();
    $section = $pw->addSection();
    $section->addText('Intro paragraph.');
    $section->addListItem('Bullet one', 0);
    $section->addListItem('Bullet two', 0);
    $section->addListItem('Nested bullet', 1);
    $section->addListItem('Numbered one', 0, null, ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER]);
    $section->addListItem('Numbered two', 0, null, ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER]);

    $path = tempnam(sys_get_temp_dir(), 'import') . '.docx';
    IOFactory::createWriter($pw, 'Word2007')->save($path);

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    $bullets = collectNodes($result['content'], 'bulletList');
    $ordered = collectNodes($result['content'], 'orderedList');

    // One top-level bullet list + the nested one inside its second item.
    expect($bullets)->toHaveCount(2);
    expect($ordered)->toHaveCount(1);

    $topItems = $bullets[0]['content'];
    expect($topItems)->toHaveCount(2);
    // The nested list lives INSIDE the second item, after its paragraph.
    expect(collect($topItems[1]['content'])->pluck('type')->all())
        ->toBe(['paragraph', 'bulletList']);

    $orderedTexts = collect(collectNodes($ordered[0], 'text'))->pluck('text')->all();
    expect($orderedTexts)->toBe(['Numbered one', 'Numbered two']);
});

test('docx import keeps formatted runs together inside one list item', function () {
    $pw = new PhpWord();
    $section = $pw->addSection();
    $run = $section->addListItemRun(0);
    $run->addText('plain then ');
    $run->addText('bold', ['bold' => true]);

    $path = tempnam(sys_get_temp_dir(), 'import') . '.docx';
    IOFactory::createWriter($pw, 'Word2007')->save($path);

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    $items = collectNodes($result['content'], 'listItem');
    expect($items)->toHaveCount(1);

    // Both runs in ONE item (the HTML writer would otherwise shred each run
    // into its own paragraph), with the bold mark intact.
    $texts = collect(collectNodes($items[0], 'text'));
    expect($texts->pluck('text')->all())->toBe(['plain then ', 'bold']);
    expect(collect($texts[1]['marks'] ?? [])->pluck('type'))->toContain('bold');
});

test('docx import list sentinels never leak into content', function () {
    $pw = new PhpWord();
    $section = $pw->addSection();
    $section->addListItem('An item', 0);
    $section->addText('A paragraph.');

    $path = tempnam(sys_get_temp_dir(), 'import') . '.docx';
    IOFactory::createWriter($pw, 'Word2007')->save($path);

    $result = app(DocxImporter::class)->import($path);
    unlink($path);

    expect(json_encode($result['content']))
        ->not->toContain("\u{E000}")
        ->not->toContain("\u{E001}");
});

/** Render one paragraph per line into a real PDF (Dompdf, like our own exports). */
function makeLinesPdf(array $lines): string
{
    $body = implode('', array_map(
        fn (string $l) => '<p>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</p>',
        $lines
    ));

    $pdf = new \Dompdf\Dompdf();
    $pdf->loadHtml('<html><body style="font-family: DejaVu Sans;">' . $body . '</body></html>', 'UTF-8');
    $pdf->render();

    $path = tempnam(sys_get_temp_dir(), 'import') . '.pdf';
    file_put_contents($path, $pdf->output());

    return $path;
}

test('pdf import detects bulleted and numbered lists heuristically', function () {
    $path = makeLinesPdf([
        'Intro line.',
        '• Alpha',
        '• Beta',
        '1. First',
        '2. Second',
        '3. Third',
        'Outro line.',
    ]);

    $result = app(\App\Services\Importers\PdfImporter::class)->import($path);
    unlink($path);

    $bullets = collectNodes($result['content'], 'bulletList');
    $ordered = collectNodes($result['content'], 'orderedList');

    expect($bullets)->toHaveCount(1);
    expect(collect(collectNodes($bullets[0], 'text'))->pluck('text')->all())
        ->toBe(['Alpha', 'Beta']);

    expect($ordered)->toHaveCount(1);
    expect(collect(collectNodes($ordered[0], 'text'))->pluck('text')->all())
        ->toBe(['First', 'Second', 'Third']);
});

test('pdf import does not mistake number-leading prose for a list', function () {
    $path = makeLinesPdf([
        '2026 was a big year for the team.',
        '5. May was the rainiest month.',
        'Normal closing line.',
    ]);

    $result = app(\App\Services\Importers\PdfImporter::class)->import($path);
    unlink($path);

    expect(collectNodes($result['content'], 'orderedList'))->toBeEmpty();
    expect(collectNodes($result['content'], 'bulletList'))->toBeEmpty();

    $texts = collect(collectNodes($result['content'], 'text'))->pluck('text')->all();
    expect($texts)->toContain('2026 was a big year for the team.');
    expect($texts)->toContain('5. May was the rainiest month.');
});

test('a failed import job marks the job failed and trashes the empty placeholder page', function () {
    login();
    $workspace = \App\Models\Workspace::factory()->create();

    $document = \App\Models\Document::factory()->create([
        'workspace_id' => $workspace->id,
        'title'        => 'Importing Broken File',
        'content'      => ['type' => 'doc', 'content' => []],
    ]);

    $job = \App\Models\ConversionJob::create([
        'document_id'   => $document->id,
        'direction'     => 'import',
        'format'        => 'docx',
        'status'        => 'processing',
        'result_path'   => 'imports/docx/missing.docx',
        'created_by_id' => auth()->id(),
    ]);

    // Simulate the queue giving up (timeout / final failure).
    (new \App\Jobs\ImportDocumentJob($job->id))->failed(new \RuntimeException('timed out'));

    expect($job->fresh()->status)->toBe('failed');
    // The empty "Importing …" placeholder is soft-deleted, not left in the tree.
    expect(\App\Models\Document::find($document->id))->toBeNull();
    expect(\App\Models\Document::withTrashed()->find($document->id))->not->toBeNull();
});

// The 50 MB ceiling is a three-layer contract: this max:51200 rule, the
// "max 50 MB" hint on the Import page, and the client_max_body_size exemption
// for the imports route in docker/nginx/default.conf. These pin the app-side
// boundary; tests/e2e/import-limit.spec.js guards the nginx side.
const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

test('import accepts a file at exactly the 50 MB limit', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();
    $workspace = \App\Models\Workspace::factory()->create();

    $file = \Illuminate\Http\UploadedFile::fake()->create('big.docx', 51200, DOCX_MIME);

    $this->post(route('imports.store', $workspace), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202);

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ImportDocumentJob::class);
});

test('import rejects a file over the 50 MB limit with a validation error', function () {
    login();
    $workspace = \App\Models\Workspace::factory()->create();

    $file = \Illuminate\Http\UploadedFile::fake()->create('big.docx', 51201, DOCX_MIME);

    $this->post(route('imports.store', $workspace), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    // No placeholder page is created for a rejected upload.
    expect(\App\Models\Document::count())->toBe(0);
});

test('an import can be filed straight into a folder', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();
    $workspace = \App\Models\Workspace::factory()->create();
    $folder    = \App\Models\DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);

    $file = \Illuminate\Http\UploadedFile::fake()->create('runbook.docx', 40, DOCX_MIME);

    $this->post(route('imports.store', $workspace), ['file' => $file, 'folder_id' => $folder->id], ['Accept' => 'application/json'])
        ->assertStatus(202);

    // The placeholder page exists in the folder from the start, so the tree
    // shows it converting in place rather than jumping there when it finishes.
    $page = \App\Models\Document::latest('id')->first();
    expect($page->folder_id)->toBe($folder->id)
        ->and($page->parent_id)->toBeNull();
});

// An import is a new page like any other: it lands at the TOP of its scope,
// not wherever the position column's default happens to sort.
test('an imported page lands at the top of its scope', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();
    $workspace = \App\Models\Workspace::factory()->create();
    $folder    = \App\Models\DocumentFolder::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);

    foreach (range(0, 2) as $i) {
        \App\Models\Document::factory()->create([
            'workspace_id' => $workspace->id,
            'folder_id'    => $folder->id,
            'position'     => $i,
        ]);
        \App\Models\Document::factory()->create([
            'workspace_id' => $workspace->id,
            'position'     => $i,
        ]);
    }

    $file = \Illuminate\Http\UploadedFile::fake()->create('runbook.docx', 40, DOCX_MIME);
    $this->post(route('imports.store', $workspace), ['file' => $file, 'folder_id' => $folder->id], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $inFolder = \App\Models\Document::latest('id')->first();
    expect($inFolder->position)->toBeLessThan(0);

    // A loose top-level page shares ONE ordering space with the workspace's
    // folders, so "top" has to clear the folders too.
    $file = \Illuminate\Http\UploadedFile::fake()->create('loose.docx', 40, DOCX_MIME);
    $this->post(route('imports.store', $workspace), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202);

    expect(\App\Models\Document::latest('id')->first()->position)->toBeLessThan(0);
});

// Importing is ONE user action spread over two saves (placeholder row now,
// content later from the queue), so it audits exactly one document.created —
// the placeholder save is silent and the job's save carries the real title.
test('an import audits one document.created, logged when the content lands', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();
    $workspace = \App\Models\Workspace::factory()->create();
    $folder    = \App\Models\DocumentFolder::factory()->create(['workspace_id' => $workspace->id]);

    $path = makeFormattedDocx();
    $file = new \Illuminate\Http\UploadedFile($path, 'network-runbook.docx', DOCX_MIME, null, true);

    // A distinct upload address: the job runs outside this request, so an event
    // carrying it can only have got it from the conversion job row.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->post(
        route('imports.store', $workspace),
        ['file' => $file, 'folder_id' => $folder->id],
        ['Accept' => 'application/json'],
    )->assertStatus(202);
    unlink($path);

    // Phase 1: the placeholder is in the tree but nothing is audited yet — an
    // import that never finishes must leave no trace of a page that never was.
    expect(\App\Models\AuditEvent::where('event', 'like', 'document.%')->count())->toBe(0);

    // Phase 2: the queue fills it in.
    (new \App\Jobs\ImportDocumentJob($response->json('job_id')))
        ->handle(app(DocxImporter::class), app(\App\Services\Importers\PdfImporter::class));

    $events = \App\Models\AuditEvent::where('event', 'like', 'document.%')->get();
    expect($events)->toHaveCount(1);

    $document = \App\Models\Document::find($response->json('document_id'));
    expect($events[0]->event)->toBe('document.created')
        ->and($events[0]->user_id)->toBe(auth()->id())
        // Written by the worker, which has no request — the uploader's address
        // rides the conversion job so the row isn't IP-less like a console run.
        ->and($events[0]->ip)->toBe('192.0.2.10')
        ->and($events[0]->context['title'])->toBe($document->title)
        ->and($events[0]->context['title'])->not->toStartWith('Importing ')
        // Provenance: the Events page reads this as "imported X from Y".
        ->and($events[0]->context['import'])->toBe('network-runbook.docx')
        ->and($events[0]->context['folder'])->toBe($folder->name);
});

/**
 * Upload a one-paragraph .docx through the import route and run its queued job,
 * returning the finished page. $docTitle sets the file's own title property —
 * null means the file carries none, like most real-world documents.
 */
function importDocx(string $filename, ?string $docTitle = null): \App\Models\Document
{
    $pw = new PhpWord();
    if ($docTitle !== null) {
        $pw->getDocInfo()->setTitle($docTitle);
    }
    $pw->addSection()->addText('Body text.');

    $path = tempnam(sys_get_temp_dir(), 'import') . '.docx';
    IOFactory::createWriter($pw, 'Word2007')->save($path);

    $workspace = \App\Models\Workspace::factory()->create();
    $file      = new \Illuminate\Http\UploadedFile($path, $filename, DOCX_MIME, null, true);

    $response = test()->post(
        route('imports.store', $workspace),
        ['file' => $file],
        ['Accept' => 'application/json'],
    );
    unlink($path);
    $response->assertStatus(202);

    (new \App\Jobs\ImportDocumentJob($response->json('job_id')))
        ->handle(app(DocxImporter::class), app(\App\Services\Importers\PdfImporter::class));

    return \App\Models\Document::findOrFail($response->json('document_id'));
}

test('an imported page keeps its filename-derived title when the file carries none', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();

    expect(importDocx('network-runbook.docx')->title)->toBe('Network Runbook');
});

// Dompdf writes no /Title metadata, so this is the same "file carries no title"
// path as the docx case — PdfImporter must not invent "Imported PDF" either.
test('an imported pdf keeps its filename-derived title when the file carries none', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();
    $workspace = \App\Models\Workspace::factory()->create();

    $path = makeLinesPdf(['Failover steps.', 'Step one.']);
    $file  = new \Illuminate\Http\UploadedFile($path, 'failover-plan.pdf', 'application/pdf', null, true);

    $response = $this->post(route('imports.store', $workspace), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(202);
    unlink($path);

    (new \App\Jobs\ImportDocumentJob($response->json('job_id')))
        ->handle(app(DocxImporter::class), app(\App\Services\Importers\PdfImporter::class));

    expect(\App\Models\Document::find($response->json('document_id'))->title)->toBe('Failover Plan');
});

test('a title stored in the file wins over the filename', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();

    expect(importDocx('network-runbook.docx', 'Q3 Network Plan')->title)->toBe('Q3 Network Plan');
});

test('an import rejects a folder from another workspace', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    login();
    $workspace = \App\Models\Workspace::factory()->create();
    $folder    = \App\Models\DocumentFolder::factory()->create();

    $file = \Illuminate\Http\UploadedFile::fake()->create('runbook.docx', 40, DOCX_MIME);

    $this->post(route('imports.store', $workspace), ['file' => $file, 'folder_id' => $folder->id], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('folder_id');

    expect(\App\Models\Document::count())->toBe(0);
});
