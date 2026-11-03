<?php

use App\Models\ConversionJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

it('creates a conversion job and dispatches export', function () {
    Queue::fake();

    $user      = login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);

    $response = $this->actingAs($user)
        ->postJson("/documents/{$document->id}/exports", ['format' => 'pdf']);

    $response->assertStatus(202)
        ->assertJsonStructure(['id', 'status']);

    expect(ConversionJob::count())->toBe(1);

    $job = ConversionJob::first();
    expect($job->format)->toBe('pdf')
        ->and($job->direction)->toBe('export')
        ->and($job->status)->toBe('pending')
        ->and($job->document_id)->toBe($document->id);

    Queue::assertPushed(\App\Jobs\ExportDocumentJob::class);
});

it('poll returns job status', function () {
    $user      = login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);
    $job       = ConversionJob::create([
        'document_id' => $document->id,
        'direction'   => 'export',
        'format'      => 'docx',
        'status'      => 'done',
        'result_path' => 'exports/docx/test.docx',
    ]);

    $this->actingAs($user)
        ->getJson("/documents/{$document->id}/exports/{$job->id}")
        ->assertStatus(200)
        ->assertJsonFragment(['status' => 'done']);
});

it('rejects invalid format', function () {
    $user      = login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->postJson("/documents/{$document->id}/exports", ['format' => 'xls'])
        ->assertStatus(422);
});

it('renders a real PDF and builds the font cache under storage, not vendor', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);

    $path = app(\App\Services\Exporters\PdfExporter::class)->export($document);

    expect(\Illuminate\Support\Facades\Storage::disk('local')->get($path))->toStartWith('%PDF');

    // Dompdf INSTALLS the @font-face fonts on first render. The install must
    // land in the www-data-writable storage dir — its vendor/ default broke
    // every prod export with "Permission denied" (the worker can't write
    // vendor/), while dev, running privileged, never noticed.
    expect(glob(storage_path('fonts/dompdf/lexend_*')))->not->toBeEmpty();
});

it('guests cannot create exports', function () {
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->postJson("/documents/{$document->id}/exports", ['format' => 'pdf'])
        ->assertStatus(401);
});
