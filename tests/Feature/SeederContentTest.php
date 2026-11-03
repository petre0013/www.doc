<?php

use App\Models\Attachment;
use App\Models\Document;
use App\Support\DocumentDiff;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LargePageSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Guards against re-introducing empty/null text nodes in seeded content.
 * ProseMirror rejects a text node whose `text` isn't a non-empty string, and a
 * single one blanks the whole page in the editor (tiptap-php renders it, so the
 * read view "works" while the editor is blank — a confusing data bug).
 */
it('the inline content builder never emits an empty text node', function () {
    $seeder = new WorkspaceSeeder;
    $inline = (new ReflectionMethod($seeder, 'inline'));
    $inline->setAccessible(true);

    $cases = [
        '[[Only A Link]]',          // a paragraph that is just a link
        'lead text [[Link]]',       // link at the end
        '[[Link]] trailing text',   // link at the start
        '[[A]] and [[B]] guides',   // multiple links
        'plain text only',
        'has `inline code` too',
        '',                         // empty paragraph
    ];

    foreach ($cases as $text) {
        foreach ($inline->invoke($seeder, $text) as $node) {
            if (($node['type'] ?? null) === 'text') {
                expect($node['text'] ?? null)->toBeString()->not->toBe('');
            }
        }
    }
});

it('seeds page attachments with real, openable binaries', function () {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);

    expect(Attachment::count())->toBeGreaterThan(0);

    $onboarding = Document::where('title', 'Onboarding')->with('attachments')->first();
    expect($onboarding)->not->toBeNull();
    expect($onboarding->attachments->pluck('original_name')->all())
        ->toContain('New Hire Checklist.pdf')
        ->toContain('Equipment Request Form.csv');

    // The PDF is a genuine PDF (valid header), the CSV is its raw text — both
    // land on the private disk so the download endpoint serves openable files.
    $pdf = $onboarding->attachments->firstWhere('original_name', 'New Hire Checklist.pdf');
    Storage::disk('local')->assertExists($pdf->path);
    expect(Storage::disk('local')->get($pdf->path))->toStartWith('%PDF-1.');
    expect($pdf->mime)->toBe('application/pdf')->and($pdf->size)->toBeGreaterThan(0);

    $csv = $onboarding->attachments->firstWhere('original_name', 'Equipment Request Form.csv');
    expect(Storage::disk('local')->get($csv->path))->toContain('item,model,notes');
});

/**
 * LargePageSeeder exists to put pages on BOTH sides of DocumentDiff's size cap
 * — that is the only reason it generates bodies nobody would write by hand. If
 * the cap moves and the fixture doesn't, it silently stops covering the case it
 * was made for, so assert the straddle rather than the byte counts.
 */
it('seeds pages on both sides of the version-diff size cap', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(LargePageSeeder::class);

    $verdicts = Document::whereIn('title', [
        'Runbook: Core Switch Replacement',
        'Runbook: Datacentre Migration Wave 2',
    ])->get()->mapWithKeys(function (Document $document) {
        [$newer, $older] = [$document->versions[0], $document->versions[1]];

        $diff = DocumentDiff::compare(
            ['title' => $older->title, 'content' => $older->content, 'tags' => $older->tags ?? []],
            ['title' => $newer->title, 'content' => $newer->content, 'tags' => $newer->tags ?? []],
        );

        expect($diff['body']['changed'])->toBeTrue();

        return [$document->title => $diff['body']['skipped']];
    });

    expect($verdicts['Runbook: Core Switch Replacement'])->toBeFalse()
        ->and($verdicts['Runbook: Datacentre Migration Wave 2'])->toBeTrue();
});

/** The diagram page's last revision changes ONLY the graph — the case where the
 *  body has nothing to diff but the diagram section still must report. */
it('seeds a long page whose newest revision changes only its diagram', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(LargePageSeeder::class);

    $document = Document::where('title', 'Capacity Review 2026 (Long, With Diagram)')->firstOrFail();
    [$newer, $older] = [$document->versions[0], $document->versions[1]];

    $diff = DocumentDiff::compare(
        ['title' => $older->title, 'content' => $older->content, 'tags' => $older->tags ?? []],
        ['title' => $newer->title, 'content' => $newer->content, 'tags' => $newer->tags ?? []],
    );

    expect($diff['body']['changed'])->toBeFalse()
        ->and($diff['diagrams'])->not->toBeEmpty();
});
