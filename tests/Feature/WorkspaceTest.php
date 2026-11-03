<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected away from workspaces', function () {
    $this->get('/workspaces')->assertRedirect('/login');
});

test('the workspaces index renders for an authenticated user', function () {
    login();
    Workspace::factory()->count(2)->create();

    $this->get('/workspaces')->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('Workspaces/Index')
            ->has('workspaces', 2)
    );
});

test('a workspace can be created and gets a slug', function () {
    login();

    $this->post('/workspaces', [
        'name' => 'Network Ops',
        'description' => 'Everything networking.',
    ])->assertRedirect();

    $workspace = Workspace::firstWhere('name', 'Network Ops');
    expect($workspace)->not->toBeNull();
    expect($workspace->slug)->toBe('network-ops');
});

test('workspace name is required', function () {
    login();

    $this->post('/workspaces', ['name' => ''])->assertSessionHasErrors('name');
});

test('a workspace is created ungrouped by default', function () {
    login();

    $this->post('/workspaces', ['name' => 'Loose Space'])->assertRedirect();

    expect(Workspace::firstWhere('name', 'Loose Space')->group_id)->toBeNull();
});

test('a new workspace lands above every existing top-level slot', function () {
    login();
    // Existing top level: a group and a loose workspace, both at the front.
    WorkspaceGroup::factory()->create(['position' => 0]);
    $loose = Workspace::factory()->create(['group_id' => null, 'position' => 0]);

    $this->post('/workspaces', ['name' => 'Newest'])->assertRedirect();

    $new = Workspace::firstWhere('name', 'Newest');
    // Strictly below the minimum top-level position (groups + loose share one
    // space), so it sorts first in the index without shifting existing rows.
    expect($new->position)->toBeLessThan(0)
        ->and($new->position)->toBeLessThan($loose->fresh()->position);
});

test('a new workspace created into a group lands above its siblings', function () {
    login();
    $group = WorkspaceGroup::factory()->create();
    $sibling = Workspace::factory()->create(['group_id' => $group->id, 'position' => 0]);

    $this->post('/workspaces', ['name' => 'FirstMember', 'group_id' => $group->id])
        ->assertRedirect();

    expect(Workspace::firstWhere('name', 'FirstMember')->position)
        ->toBeLessThan($sibling->fresh()->position);
});

test('a workspace can be created straight into a group', function () {
    login();
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);

    $this->post('/workspaces', [
        'name'     => 'BitLocker',
        'group_id' => $group->id,
    ])->assertRedirect();

    expect(Workspace::firstWhere('name', 'BitLocker')->group_id)->toBe($group->id);

    // The group it was born in rides along in the audit context; a later move is
    // its own workspace.moved event.
    $event = AuditEvent::where('event', 'workspace.created')->latest('id')->first();
    expect($event->context['group'])->toBe('Security');
});

test('creating a workspace in a non-existent group is rejected', function () {
    login();

    $this->post('/workspaces', ['name' => 'Nope', 'group_id' => 9999])
        ->assertSessionHasErrors('group_id');
});

test('a workspace can be updated', function () {
    login();
    $workspace = Workspace::factory()->create(['name' => 'Old']);

    $this->patch("/workspaces/{$workspace->id}", ['name' => 'New'])->assertRedirect();

    expect($workspace->fresh()->name)->toBe('New');
});

test('a workspace description can be added and cleared after creation', function () {
    login();
    $workspace = Workspace::factory()->create(['description' => null]);

    // Add
    $this->patch("/workspaces/{$workspace->id}", [
        'name' => $workspace->name,
        'description' => 'Everything networking.',
    ])->assertRedirect();
    expect($workspace->fresh()->description)->toBe('Everything networking.');

    // Clear (blank description arrives as null from the edit dialog)
    $this->patch("/workspaces/{$workspace->id}", [
        'name' => $workspace->name,
        'description' => null,
    ])->assertRedirect();
    expect($workspace->fresh()->description)->toBeNull();
});

test('deleting a workspace soft-deletes it and its documents', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->delete("/workspaces/{$workspace->id}")->assertRedirect('/workspaces');

    $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
    $this->assertSoftDeleted('documents', ['id' => $document->id]);
});

test('a trashed workspace can be restored with its documents', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->trashWithDocuments();

    $this->post("/trash/workspaces/{$workspace->id}/restore")->assertRedirect();

    expect(Workspace::find($workspace->id))->not->toBeNull();
    expect(Document::find($document->id))->not->toBeNull();
});

test('restoring a workspace leaves individually-trashed pages in the trash', function () {
    login();
    $workspace = Workspace::factory()->create();
    $kept      = Document::factory()->create(['workspace_id' => $workspace->id]);
    $alreadyGone = Document::factory()->create(['workspace_id' => $workspace->id]);

    // A page the user trashed on its own, before the whole workspace went away.
    $alreadyGone->trashSubtree();
    $workspace->trashWithDocuments();

    $this->post("/trash/workspaces/{$workspace->id}/restore")->assertRedirect();

    expect(Document::find($kept->id))->not->toBeNull();
    $this->assertSoftDeleted('documents', ['id' => $alreadyGone->id]);
});

test('force-deleting a trashed workspace destroys it and its documents', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->trashWithDocuments();

    $this->delete("/trash/workspaces/{$workspace->id}")->assertRedirect();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
});

test('the workspace show page returns the document tree', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->get("/workspaces/{$workspace->id}")->assertOk()->assertInertia(
        fn (Assert $page) => $page->component('Workspaces/Show')->has('tree')
    );
});

test('workspaces are reordered by the given id order without bumping updated_at', function () {
    login();
    $first  = Workspace::factory()->create(['position' => 0]);
    $second = Workspace::factory()->create(['position' => 1]);
    $stamp  = $second->updated_at;

    $this->patch('/workspaces/reorder', ['ids' => [$second->id, $first->id]])->assertRedirect();

    expect($second->fresh()->position)->toBe(0);
    expect($first->fresh()->position)->toBe(1);
    // Reordering is structural — it must not touch the activity timestamp.
    expect($second->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

test('reorder rejects ids that do not exist', function () {
    login();
    Workspace::factory()->create();

    $this->patch('/workspaces/reorder', ['ids' => [999999]])
        ->assertSessionHasErrors('ids.0');
});
