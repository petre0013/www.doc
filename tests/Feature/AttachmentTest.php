<?php

use App\Models\Attachment;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Attachments live on the private 'local' disk.
    Storage::fake('local');
});

it('editor can upload an attachment to a page', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $file = UploadedFile::fake()->create('handbook.pdf', 12, 'application/pdf');

    $this->post("/documents/{$document->id}/attachments", ['file' => $file])
        ->assertNoContent();

    expect($document->attachments()->count())->toBe(1);

    $attachment = $document->attachments()->first();
    expect($attachment->original_name)->toBe('handbook.pdf')
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->mime)->toBe('application/pdf')
        ->and($attachment->checksum)->not->toBeNull()
        ->and($attachment->uploaded_by_id)->not->toBeNull();

    Storage::disk('local')->assertExists($attachment->path);
});

it('uses a provided display name as the original name', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", [
        'file' => UploadedFile::fake()->create('raw-upload-name.pdf', 5, 'application/pdf'),
        'name' => 'Employee Handbook.pdf',
    ])->assertNoContent();

    expect($document->attachments()->first()->original_name)->toBe('Employee Handbook.pdf');
});

it('forces the real file extension, ignoring one typed in the name', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", [
        'file' => UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'),
        'name' => 'Report.txt', // wrong extension — must be replaced with .pdf
    ])->assertNoContent();

    expect($document->attachments()->first()->original_name)->toBe('Report.pdf');
});

it('falls back to the uploaded filename when no name is given', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", [
        'file' => UploadedFile::fake()->create('fallback.pdf', 5, 'application/pdf'),
        'name' => '   ',
    ])->assertNoContent();

    expect($document->attachments()->first()->original_name)->toBe('fallback.pdf');
});

it('assigns increasing positions to attachments', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 1)]);
    $this->post("/documents/{$document->id}/attachments", ['file' => UploadedFile::fake()->create('b.pdf', 1)]);

    expect($document->attachments()->pluck('position')->all())->toBe([1, 2]);
});

it('rejects files over 25 MB', function () {
    login(role: 'editor');
    $document = Document::factory()->create();

    $file = UploadedFile::fake()->create('huge.zip', 25 * 1024 + 1); // > 25 MB in KB

    $this->post("/documents/{$document->id}/attachments", ['file' => $file])
        ->assertSessionHasErrors('file');

    expect($document->attachments()->count())->toBe(0);
});

it('viewers cannot upload attachments', function () {
    login(role: 'viewer');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", ['file' => UploadedFile::fake()->create('x.pdf', 1)])
        ->assertForbidden();

    expect($document->attachments()->count())->toBe(0);
});

it('guests cannot upload attachments', function () {
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", ['file' => UploadedFile::fake()->create('x.pdf', 1)])
        ->assertRedirect('/login');
});

it('lets a viewer download an attachment with its original filename', function () {
    login(role: 'viewer');
    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/abc.pdf', 'PDF BYTES');
    $attachment = Attachment::factory()->for($document)->create([
        'path' => 'attachments/abc.pdf',
        'original_name' => 'Quarterly Report.pdf',
    ]);

    $response = $this->get("/documents/{$document->id}/attachments/{$attachment->id}");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('Quarterly Report.pdf');
});

it('404s when the attachment belongs to another document', function () {
    login(role: 'editor');
    $docA = Document::factory()->create();
    $docB = Document::factory()->create();
    $attachment = Attachment::factory()->for($docB)->create();

    $this->get("/documents/{$docA->id}/attachments/{$attachment->id}")
        ->assertNotFound();

    $this->patch("/documents/{$docA->id}/attachments/{$attachment->id}", ['name' => 'Nope'])
        ->assertNotFound();

    $this->delete("/documents/{$docA->id}/attachments/{$attachment->id}")
        ->assertNotFound();
});

it('editor can delete an attachment and its binary is removed', function () {
    login(role: 'editor');
    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/gone.pdf', 'bytes');
    $attachment = Attachment::factory()->for($document)->create(['path' => 'attachments/gone.pdf']);

    $this->delete("/documents/{$document->id}/attachments/{$attachment->id}")
        ->assertNoContent();

    expect(Attachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing('attachments/gone.pdf');
});

it('viewers cannot delete attachments', function () {
    login(role: 'viewer');
    $document = Document::factory()->create();
    $attachment = Attachment::factory()->for($document)->create();

    $this->delete("/documents/{$document->id}/attachments/{$attachment->id}")
        ->assertForbidden();

    expect(Attachment::find($attachment->id))->not->toBeNull();
});

it('editor can rename an attachment', function () {
    login(role: 'editor');
    $document = Document::factory()->create();
    $attachment = Attachment::factory()->for($document)->create([
        'path' => 'attachments/keep.pdf',
        'original_name' => 'Old Name.pdf',
    ]);

    $this->patch("/documents/{$document->id}/attachments/{$attachment->id}", ['name' => 'New Name'])
        ->assertNoContent();

    expect($attachment->fresh()->original_name)->toBe('New Name.pdf');
});

it('re-pins the real extension when renaming, ignoring one typed in the name', function () {
    login(role: 'editor');
    $document = Document::factory()->create();
    $attachment = Attachment::factory()->for($document)->create([
        'path' => 'attachments/keep.pdf',
        'original_name' => 'Report.pdf',
    ]);

    $this->patch("/documents/{$document->id}/attachments/{$attachment->id}", ['name' => 'Report.txt'])
        ->assertNoContent();

    expect($attachment->fresh()->original_name)->toBe('Report.pdf');
});

it('rejects a blank rename', function () {
    login(role: 'editor');
    $document = Document::factory()->create();
    $attachment = Attachment::factory()->for($document)->create(['original_name' => 'Keep.pdf']);

    $this->patchJson("/documents/{$document->id}/attachments/{$attachment->id}", ['name' => '   '])
        ->assertStatus(422);

    expect($attachment->fresh()->original_name)->toBe('Keep.pdf');
});

it('viewers cannot rename attachments', function () {
    login(role: 'viewer');
    $document = Document::factory()->create();
    $attachment = Attachment::factory()->for($document)->create(['original_name' => 'Keep.pdf']);

    $this->patch("/documents/{$document->id}/attachments/{$attachment->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    expect($attachment->fresh()->original_name)->toBe('Keep.pdf');
});

it('purging a document removes its attachment binaries', function () {
    login(role: 'admin');
    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/purge.pdf', 'bytes');
    Attachment::factory()->for($document)->create(['path' => 'attachments/purge.pdf']);

    $document->delete();              // soft delete → trash
    $document->forceDeleteSubtree();  // admin purge from trash

    Storage::disk('local')->assertMissing('attachments/purge.pdf');
    expect(Attachment::count())->toBe(0);
});

it('purging a workspace removes its pages attachment binaries', function () {
    login(role: 'admin');
    $workspace = \App\Models\Workspace::factory()->create();
    $document = Document::factory()->for($workspace)->create();
    Storage::disk('local')->put('attachments/ws.pdf', 'bytes');
    Attachment::factory()->for($document)->create(['path' => 'attachments/ws.pdf']);

    $workspace->delete();                      // soft delete → trash
    $workspace->forceDeleteWithDocuments();    // admin purge from trash

    Storage::disk('local')->assertMissing('attachments/ws.pdf');
    expect(Attachment::count())->toBe(0);
});

it('exposes attachments on the page show props', function () {
    login(role: 'editor');
    $document = Document::factory()->create();
    Attachment::factory()->for($document)->create(['original_name' => 'spec.pdf']);

    $this->get("/documents/{$document->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Documents/Show')
            ->where('document.attachments.0.original_name', 'spec.pdf'));
});
