<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Template;
use App\Models\Workspace;

// ── Authorization ─────────────────────────────────────────────────────────────

test('viewers cannot see or touch templates', function () {
    login(role: 'viewer');
    $template = Template::factory()->create();

    $this->get('/templates')->assertForbidden();
    $this->get("/templates/{$template->id}/edit")->assertForbidden();
    $this->post('/templates', ['name' => 'Nope'])->assertForbidden();
    $this->patch("/templates/{$template->id}", ['name' => 'Nope'])->assertForbidden();
    $this->delete("/templates/{$template->id}")->assertForbidden();
});

test('editors manage templates end to end', function () {
    $editor = login(role: 'editor');

    // Create → lands in the template editor, creator stamped, audited.
    $this->post('/templates', ['name' => 'Runbook', 'description' => 'Ops procedure'])
        ->assertRedirect();
    $template = Template::firstWhere('name', 'Runbook');
    expect($template)->not->toBeNull()
        ->and($template->created_by_id)->toBe($editor->id)
        ->and(AuditEvent::firstWhere('event', 'template.created')?->context['name'])->toBe('Runbook');

    // Index lists it.
    $this->get('/templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Templates/Index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Runbook'));

    // Update content + metadata, audited.
    $content = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Step one']]],
    ]];
    $this->patch("/templates/{$template->id}", [
        'name'    => 'Runbook v2',
        'content' => $content,
    ])->assertRedirect();

    expect($template->fresh()->name)->toBe('Runbook v2')
        ->and($template->fresh()->content)->toEqual($content)
        ->and(AuditEvent::where('event', 'template.updated')->count())->toBe(1);

    // Delete is hard and audited with the identity in context (no subject).
    $this->delete("/templates/{$template->id}")->assertRedirect(route('templates.index'));
    $deleted = AuditEvent::firstWhere('event', 'template.deleted');
    expect(Template::count())->toBe(0)
        ->and($deleted->auditable_id)->toBeNull()
        ->and($deleted->context['name'])->toBe('Runbook v2')
        ->and($deleted->context['template_id'])->toBe($template->id);
});

// ── Instantiation ─────────────────────────────────────────────────────────────

test('creating a page from a template copies its content verbatim', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $template  = Template::factory()->create(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'callout', 'attrs' => ['kind' => 'info'], 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From the template']]],
        ]],
    ]]]);

    $this->post('/documents', [
        'title'        => 'New runbook',
        'workspace_id' => $workspace->id,
        'template_id'  => $template->id,
    ])->assertRedirect();

    $document = Document::firstWhere('title', 'New runbook');
    expect($document->content)->toEqual($template->content)
        // The copy snapshots v1 like any hand-written content.
        ->and($document->versions()->count())->toBe(1);

    // ONE document.created event for the action, carrying the template name.
    $created = AuditEvent::where('event', 'document.created')->get();
    expect($created)->toHaveCount(1)
        ->and($created->first()->context['template'])->toBe($template->name)
        ->and(AuditEvent::where('event', 'document.updated')->count())->toBe(0);
});

test('a page created without a template stays blank and un-templated', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'title'        => 'Plain page',
        'workspace_id' => $workspace->id,
    ])->assertRedirect();

    $created = AuditEvent::firstWhere('event', 'document.created');
    expect(Document::firstWhere('title', 'Plain page')->versions()->count())->toBe(0)
        ->and($created->context)->not->toHaveKey('template');
});

// ── Save as template ──────────────────────────────────────────────────────────

test('a page can be saved as a template', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Reusable body']]],
    ]]]);

    $this->post("/documents/{$document->id}/template", [
        'name'        => 'From a page',
        'description' => 'Snapshot of a live page',
    ])->assertRedirect();

    $template = Template::firstWhere('name', 'From a page');
    expect($template->content)->toEqual($document->content)
        ->and(AuditEvent::firstWhere('event', 'template.created')?->context['from_document'])
            ->toBe($document->title);
});

// ── Backups ───────────────────────────────────────────────────────────────────

test('templates round-trip through backup and restore', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    login();

    $template = Template::factory()->create(['name' => 'Survives restore']);

    $backup = \App\Models\Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(\App\Services\Backup\BackupService::class)->run($backup->fresh());
    expect($backup->fresh()->manifest['counts']['templates'])->toBe(1);

    Template::query()->delete();
    Template::factory()->create(['name' => 'Post-backup template']); // wiped by restore

    app(\App\Services\Backup\RestoreService::class)->restore($backup->fresh());

    expect(Template::count())->toBe(1)
        ->and(Template::first()->name)->toBe('Survives restore')
        ->and(Template::first()->content)->toEqual($template->content);
});
