<?php

use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Document;
use App\Models\DocumentControl;
use App\Models\DocumentControlEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function docWithText(string $text = 'Version one'): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

/** Sets the Pest login roles up, then returns an editor, an approver and a controlled page. */
function controlledPage(): array
{
    $approver = User::factory()->create();
    $editor = login(role: 'editor');
    $approver->assignRole('viewer');

    $document = Document::factory()->create(['content' => docWithText()]);
    $control = $document->control()->create([
        'doc_number' => 'POL-1', 'owner_id' => $editor->id, 'approver_id' => $approver->id,
        'classification' => 'internal', 'review_interval_months' => 6,
    ]);

    return [$editor, $approver, $document->fresh(), $control];
}

function approveThroughWorkflow(User $editor, User $approver, Document $document): void
{
    test()->actingAs($editor)->post("/documents/{$document->id}/control/submit")->assertSessionHas('success');
    test()->actingAs($approver)->post("/documents/{$document->id}/control/approve")->assertSessionHas('success');
}

test('an editor can place a page under control and an ID is generated', function () {
    $editor = login(role: 'editor');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/control", [
        'owner_id' => $editor->id, 'approver_id' => $editor->id, 'classification' => 'confidential', 'review_interval_months' => 12,
    ])->assertSessionHasNoErrors();

    expect($document->control()->first()->doc_number)->toStartWith('DOC-')
        ->and(AuditEvent::where('event', 'control.enabled')->exists())->toBeTrue();
});

test('viewers cannot place a page under control', function () {
    $viewer = login(role: 'viewer');
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/control", [
        'owner_id' => $viewer->id, 'approver_id' => $viewer->id, 'classification' => 'internal', 'review_interval_months' => 12,
    ])->assertForbidden();
});

test('owner and approver must be able to open the page', function () {
    $editor = login();
    $outsider = User::factory()->create();
    $outsider->assignRole('viewer');
    $workspace = Workspace::factory()->create(['restricted' => true]);
    $document = Document::factory()->for($workspace)->create();

    $this->post("/documents/{$document->id}/control", [
        'owner_id' => $editor->id, 'approver_id' => $outsider->id, 'classification' => 'internal', 'review_interval_months' => 12,
    ])->assertSessionHasErrors('approver_id');
});

test('approval binds the exact version, sets the review date and records a hash', function () {
    [$editor, $approver, $document] = controlledPage();

    approveThroughWorkflow($editor, $approver, $document);

    $control = $document->control()->first();
    $version = $document->versions()->latest('id')->first();
    $event = DocumentControlEvent::where('action', 'approved')->first();

    expect($control->status)->toBe('approved')
        ->and($control->approved_version_id)->toBe($version->id)
        ->and($control->next_review_at->toDateString())->toBe(today()->addMonthsNoOverflow(6)->toDateString())
        ->and($event->content_hash)->toBe(DocumentControl::hashVersion($version))
        ->and($event->actor_email)->toBe($approver->email)
        ->and(AuditEvent::firstWhere('event', 'control.approved')->context['hash'])->toBe($event->content_hash);
});

test('only the named approver can decide', function () {
    [$editor, $approver, $document] = controlledPage();
    $other = User::factory()->create();
    $other->assignRole('admin');

    $this->post("/documents/{$document->id}/control/submit");

    $this->actingAs($other)->post("/documents/{$document->id}/control/approve")->assertForbidden();
    $this->actingAs($other)->post("/documents/{$document->id}/control/reject", ['comment' => 'x'])->assertForbidden();
    expect($document->control()->first()->status)->toBe('in_review');
});

test('nobody can approve their own submission', function () {
    [$editor, , $document, $control] = controlledPage();
    $control->update(['approver_id' => $editor->id]);

    $this->post("/documents/{$document->id}/control/submit");
    $this->post("/documents/{$document->id}/control/approve")->assertSessionHas('error');

    expect($control->fresh()->status)->toBe('in_review');
});

test('an approver cannot approve content they wrote, even if someone else submitted it', function () {
    [$editor, $approver, $document] = controlledPage();
    $approver->syncRoles(['editor']);

    $this->actingAs($approver)->patch("/documents/{$document->id}/rename", ['title' => 'My own wording']);
    $this->actingAs($editor)->post("/documents/{$document->id}/control/submit");

    $this->actingAs($approver)->post("/documents/{$document->id}/control/approve")->assertSessionHas('error');
    expect($document->control()->first()->status)->toBe('in_review');
});

test('editing after submission forces a new submission', function () {
    [$editor, $approver, $document] = controlledPage();

    $this->post("/documents/{$document->id}/control/submit");
    $this->patch("/documents/{$document->id}/rename", ['title' => 'Changed']);

    $this->actingAs($approver)->post("/documents/{$document->id}/control/approve")->assertSessionHas('error');
    expect($document->control()->first()->approved_version_id)->toBeNull();
});

test('sending back needs a comment and keeps the previous approval in force', function () {
    [$editor, $approver, $document, $control] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);
    $approvedId = $control->fresh()->approved_version_id;

    $this->actingAs($editor)->patch("/documents/{$document->id}/rename", ['title' => 'Revised']);
    $this->post("/documents/{$document->id}/control/submit");

    $this->actingAs($approver)->post("/documents/{$document->id}/control/reject")->assertSessionHasErrors('comment');
    $this->post("/documents/{$document->id}/control/reject", ['comment' => 'Fix section 2'])->assertSessionHas('success');

    expect($control->fresh())->status->toBe('approved')->approved_version_id->toBe($approvedId);
});

test('readers see the approved version while editors see the draft', function () {
    [$editor, $approver, $document] = controlledPage();
    $reader = User::factory()->create();
    $reader->assignRole('viewer');
    approveThroughWorkflow($editor, $approver, $document);

    $document->fresh()->update(['title' => 'Draft title', 'content' => docWithText('Unapproved words')]);

    $this->actingAs($reader)->get("/documents/{$document->id}")->assertInertia(fn (Assert $p) => $p
        ->where('document.content.content.0.content.0.text', 'Version one')
        ->where('control.serving_approved', true));

    $this->actingAs($editor)->get("/documents/{$document->id}")->assertInertia(fn (Assert $p) => $p
        ->where('document.title', 'Draft title')
        ->where('document.content.content.0.content.0.text', 'Unapproved words')
        ->where('control.has_unapproved_changes', true));

    expect($document->fresh()->title)->toBe('Draft title');
});

test('previews use the approved version for readers', function () {
    [$editor, $approver, $document] = controlledPage();
    $reader = User::factory()->create();
    $reader->assignRole('viewer');
    approveThroughWorkflow($editor, $approver, $document);
    $document->fresh()->update(['content' => docWithText('Unapproved words')]);

    $this->actingAs($reader)->getJson("/documents/{$document->id}/preview")->assertJsonPath('excerpt', 'Version one');
    $this->actingAs($editor)->getJson("/documents/{$document->id}/preview")->assertJsonPath('excerpt', 'Unapproved words');
});

test('a hash mismatch on the approved version is flagged', function () {
    [$editor, $approver, $document] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);

    DB::table('document_versions')->where('document_id', $document->id)->update(['title' => 'Forged']);

    $this->actingAs($editor)->get("/documents/{$document->id}")
        ->assertInertia(fn (Assert $p) => $p->where('control.hash_intact', false));
});

test('the sign-off log cannot be changed in the database', function (string $sql) {
    [$editor, $approver, $document] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);

    expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(Illuminate\Database\QueryException::class);
})->with([
    ["UPDATE document_control_events SET actor_name = 'x'"],
    ['DELETE FROM document_control_events'],
    ['TRUNCATE document_control_events'],
]);

test('sign-offs outlive a purged page', function () {
    [$editor, $approver, $document] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);

    $document->fresh()->forceDelete();

    expect(DocumentControlEvent::where('document_id', $document->id)->count())->toBe(2)
        ->and(DocumentControl::where('document_id', $document->id)->exists())->toBeFalse();
});

test('a review restarts the cycle and is recorded', function () {
    [$editor, $approver, $document, $control] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);
    $control->fresh()->update(['next_review_at' => today()->subDay()]);
    expect($control->fresh()->isOverdue())->toBeTrue();

    $this->actingAs($approver)->post("/documents/{$document->id}/control/review")->assertSessionHas('success');

    expect($control->fresh()->isOverdue())->toBeFalse()
        ->and(DocumentControlEvent::where('action', 'reviewed')->exists())->toBeTrue();
});

test('only the owner or an admin can edit details or retire', function () {
    [$editor, , $document, $control] = controlledPage();
    $otherEditor = User::factory()->create();
    $otherEditor->assignRole('editor');

    $this->actingAs($otherEditor)->patch("/documents/{$document->id}/control", [
        'owner_id' => $otherEditor->id, 'approver_id' => $otherEditor->id, 'classification' => 'public', 'review_interval_months' => 1,
    ])->assertForbidden();
    $this->actingAs($otherEditor)->post("/documents/{$document->id}/control/retire")->assertForbidden();

    $this->actingAs($editor)->post("/documents/{$document->id}/control/retire")->assertSessionHas('success');
    expect($control->fresh())->status->toBe('retired')->next_review_at->toBeNull();
});

test('an owner cannot swap the approver during a review, but an admin can', function () {
    [$editor, $approver, $document, $control] = controlledPage();
    $this->post("/documents/{$document->id}/control/submit");
    $payload = ['owner_id' => $editor->id, 'approver_id' => $editor->id, 'classification' => 'internal', 'review_interval_months' => 6];

    $this->patch("/documents/{$document->id}/control", $payload)->assertSessionHas('error');
    expect($control->fresh()->approver_id)->toBe($approver->id);

    login();
    $this->patch("/documents/{$document->id}/control", $payload)->assertSessionHas('success');
    expect($control->fresh()->approver_id)->toBe($editor->id);
});

test('the register shows what was in force on a past date', function () {
    [$editor, $approver, $document, $control] = controlledPage();

    $this->travelTo(now()->subDays(30));
    approveThroughWorkflow($editor, $approver, $document);
    $first = $control->fresh()->approved_version_id;
    $this->travelBack();

    $this->actingAs($editor);
    $document->fresh()->update(['title' => 'Second edition']);
    approveThroughWorkflow($editor, $approver, $document);
    $second = $control->fresh()->approved_version_id;

    $this->get('/document-control?as_of=' . now()->subDays(10)->toDateString())
        ->assertInertia(fn (Assert $p) => $p->where('controls.0.in_force.version_id', $first));
    $this->get('/document-control')
        ->assertInertia(fn (Assert $p) => $p->where('controls.0.in_force.version_id', $second));
    $this->get('/document-control?as_of=' . now()->subYears(2)->toDateString())
        ->assertInertia(fn (Assert $p) => $p->where('controls.0.in_force', null));
});

test('retirement ends what is in force from that date', function () {
    [$editor, $approver, $document] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);

    $this->actingAs($editor)->post("/documents/{$document->id}/control/retire");

    $this->get('/document-control')->assertInertia(fn (Assert $p) => $p->where('controls.0.in_force', null));
});

test('the register hides restricted documents from non-members', function () {
    [, , $document] = controlledPage();
    Workspace::whereKey($document->workspace_id)->update(['restricted' => true]);

    $this->get('/document-control')->assertInertia(fn (Assert $p) => $p->has('controls', 0));
});

test('non-members get a 404 on every workflow step', function (string $step) {
    [$editor, , $document] = controlledPage();
    Workspace::whereKey($document->workspace_id)->update(['restricted' => true]);

    $this->post("/documents/{$document->id}/control/{$step}", ['comment' => 'x'])->assertNotFound();
})->with(['submit', 'approve', 'reject', 'review', 'retire']);

test('control state and sign-offs survive a backup and restore', function () {
    Storage::fake('local');
    [$editor, $approver, $document, $control] = controlledPage();
    approveThroughWorkflow($editor, $approver, $document);
    $approvedId = $control->fresh()->approved_version_id;

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);
    app(RestoreService::class)->restore($backup->fresh());

    expect(DocumentControl::firstWhere('document_id', $document->id))
        ->status->toBe('approved')
        ->approved_version_id->toBe($approvedId)
        ->approver_id->toBe($approver->id)
        ->and(DocumentControlEvent::where('document_id', $document->id)->count())->toBe(2);
});
