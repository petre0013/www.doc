<?php

use App\Models\AuditEvent;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('a workspace group generates a unique slug from its name', function () {
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);

    expect($group->slug)->toBe('security');
});

test('a group holds its workspaces in position order', function () {
    $group = WorkspaceGroup::factory()->create();
    Workspace::factory()->create(['group_id' => $group->id, 'position' => 2]);
    Workspace::factory()->create(['group_id' => $group->id, 'position' => 1]);
    Workspace::factory()->create(); // ungrouped — must not appear

    expect($group->workspaces)->toHaveCount(2)
        ->and($group->workspaces->pluck('position')->all())->toBe([1, 2]);
});

test('existing workspaces are ungrouped by default and resolve a null group', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->group_id)->toBeNull()
        ->and($workspace->group)->toBeNull();
});

test('a workspace resolves the group it belongs to', function () {
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);
    $workspace = Workspace::factory()->create(['group_id' => $group->id]);

    expect($workspace->group->is($group))->toBeTrue();
});

// --- Group CRUD -------------------------------------------------------------

test('an editor can create a group, which is audited', function () {
    login(role: 'editor');

    $this->post('/workspaces/groups', ['name' => 'Security'])->assertRedirect();

    $group = WorkspaceGroup::firstWhere('name', 'Security');
    expect($group)->not->toBeNull()->and($group->slug)->toBe('security');

    $event = AuditEvent::firstWhere('event', 'group.created');
    expect($event)->not->toBeNull()
        ->and($event->context['name'])->toBe('Security');
});

test('renaming a group audits group.renamed with from and to', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create(['name' => 'Sec']);

    $this->patch("/workspaces/groups/{$group->id}", ['name' => 'Security'])->assertRedirect();

    expect($group->fresh()->name)->toBe('Security');
    $event = AuditEvent::firstWhere('event', 'group.renamed');
    expect($event->context)->toMatchArray(['from' => 'Sec', 'to' => 'Security']);
});

test('a viewer cannot create a group', function () {
    login(role: 'viewer');

    $this->post('/workspaces/groups', ['name' => 'Security'])->assertForbidden();
    expect(WorkspaceGroup::count())->toBe(0);
});

// --- Deleting a group reverts its workspaces to ungrouped -------------------

test('deleting a group reverts its workspaces to ungrouped without trashing them', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);
    $a = Workspace::factory()->create(['group_id' => $group->id]);
    $b = Workspace::factory()->create(['group_id' => $group->id]);

    // Freeze both workspaces' activity timestamps clearly in the past so a
    // stray bump would be unmistakable.
    $past = Carbon::parse('2026-01-01 00:00:00');
    DB::table('workspaces')->whereIn('id', [$a->id, $b->id])->update(['updated_at' => $past]);

    $this->delete("/workspaces/groups/{$group->id}")->assertRedirect();

    // Group gone (hard delete), workspaces still live but ungrouped...
    expect(WorkspaceGroup::find($group->id))->toBeNull()
        ->and($a->fresh()->group_id)->toBeNull()
        ->and($a->fresh()->trashed())->toBeFalse()
        ->and($b->fresh()->group_id)->toBeNull();

    // ...and un-filing is structural: updated_at must not move.
    expect($a->fresh()->updated_at->equalTo($past))->toBeTrue();

    $event = AuditEvent::firstWhere('event', 'group.deleted');
    expect($event->context)->toMatchArray(['group_id' => $group->id, 'name' => 'Security']);
});

// --- Re-grouping a workspace is structural (no updated_at bump) --------------

test('filing a workspace into a group sets group_id without bumping updated_at and audits the move', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);
    $ws = Workspace::factory()->create(['group_id' => null]);

    $past = Carbon::parse('2026-01-01 00:00:00');
    DB::table('workspaces')->where('id', $ws->id)->update(['updated_at' => $past]);

    $this->patch("/workspaces/{$ws->id}/group", ['group_id' => $group->id])->assertRedirect();

    expect($ws->fresh()->group_id)->toBe($group->id)
        ->and($ws->fresh()->updated_at->equalTo($past))->toBeTrue();

    $event = AuditEvent::firstWhere('event', 'workspace.moved');
    expect($event->context)->toMatchArray(['from_group' => null, 'to_group' => 'Security']);
});

test('the workspaces index exposes groups and each workspace group_id', function () {
    login();
    $group = WorkspaceGroup::factory()->create(['name' => 'Security']);
    Workspace::factory()->create(['group_id' => $group->id]);
    Workspace::factory()->create(['group_id' => null]);

    $this->get('/workspaces')->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('Workspaces/Index')
            ->has('groups', 1)
            ->where('groups.0.name', 'Security')
            ->has('workspaces', 2)
            ->has('workspaces.0.group_id')
    );
});

test('reordering within the same group is not audited as a move', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create();
    $ws = Workspace::factory()->create(['group_id' => $group->id, 'position' => 0]);

    $this->patch("/workspaces/{$ws->id}/group", [
        'group_id' => $group->id,
        'position' => 3,
    ])->assertRedirect();

    expect($ws->fresh()->position)->toBe(3)
        ->and(AuditEvent::where('event', 'workspace.moved')->count())->toBe(0);
});

// --- Interleaved top-level reorder (groups + ungrouped share one order) ------

test('the top level reorders groups and ungrouped workspaces in one shared position space', function () {
    login(role: 'editor');

    $g1 = WorkspaceGroup::factory()->create(['name' => 'Alpha', 'position' => 0]);
    $g2 = WorkspaceGroup::factory()->create(['name' => 'Beta', 'position' => 1]);
    $w1 = Workspace::factory()->create(['group_id' => null, 'position' => 0]);
    $w2 = Workspace::factory()->create(['group_id' => null, 'position' => 1]);

    // Target order interleaves a loose workspace BETWEEN two groups: g1, w1, g2, w2.
    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'group',     'id' => $g1->id],
        ['type' => 'workspace', 'id' => $w1->id],
        ['type' => 'group',     'id' => $g2->id],
        ['type' => 'workspace', 'id' => $w2->id],
    ]])->assertRedirect();

    // Global 0..N-1 indices span both tables, so the interleaved order is exact.
    expect($g1->fresh()->position)->toBe(0)
        ->and($w1->fresh()->position)->toBe(1)
        ->and($g2->fresh()->position)->toBe(2)
        ->and($w2->fresh()->position)->toBe(3);
});

test('the same save orders group members via the groups companion axis', function () {
    login(role: 'editor');

    $group = WorkspaceGroup::factory()->create(['position' => 0]);
    $m1 = Workspace::factory()->create(['group_id' => $group->id, 'position' => 0]);
    $m2 = Workspace::factory()->create(['group_id' => $group->id, 'position' => 1]);

    // Move m2 ahead of m1 within the group, in the same atomic save as the top level.
    $this->patch('/workspaces/top-level-order', [
        'items'  => [['type' => 'group', 'id' => $group->id]],
        'groups' => [['id' => $group->id, 'members' => [$m2->id, $m1->id]]],
    ])->assertRedirect();

    expect($m2->fresh()->position)->toBe(0)
        ->and($m1->fresh()->position)->toBe(1)
        // Same group, new slot: layout noise, so not a move.
        ->and(AuditEvent::where('event', 'workspace.moved')->count())->toBe(0);
});

test('the top-level reorder rejects a workspace that holds two slots', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create(['position' => 0]);
    $loose = Workspace::factory()->create(['group_id' => null, 'position' => 3]);

    // The same row as both a loose top-level item AND a group member — it can't
    // carry two positions/groups at once.
    $this->patch('/workspaces/top-level-order', [
        'items'  => [
            ['type' => 'group', 'id' => $group->id],
            ['type' => 'workspace', 'id' => $loose->id],
        ],
        'groups' => [['id' => $group->id, 'members' => [$loose->id]]],
    ])->assertStatus(422);

    expect($loose->fresh()->position)->toBe(3)
        ->and($loose->fresh()->group_id)->toBeNull();
});

// --- Batched refiling: a drag between groups rides the same "Done" ------------

test('the batched save files a loose workspace into a group, structurally and audited', function () {
    login(role: 'editor');
    $group = WorkspaceGroup::factory()->create(['name' => 'Security', 'position' => 0]);
    $loose = Workspace::factory()->create(['group_id' => null, 'position' => 0]);

    $past = Carbon::parse('2026-01-01 00:00:00');
    DB::table('workspaces')->where('id', $loose->id)->update(['updated_at' => $past]);

    $this->patch('/workspaces/top-level-order', [
        'items'  => [['type' => 'group', 'id' => $group->id]],
        'groups' => [['id' => $group->id, 'members' => [$loose->id]]],
    ])->assertRedirect();

    expect($loose->fresh()->group_id)->toBe($group->id)
        // A refile is structural — it must not read as an edit.
        ->and($loose->fresh()->updated_at->equalTo($past))->toBeTrue();

    $event = AuditEvent::firstWhere('event', 'workspace.moved');
    expect($event->context)->toMatchArray(['from_group' => null, 'to_group' => 'Security']);
});

test('presenting a grouped workspace at the top level refiles it to loose and audits the move', function () {
    login(role: 'editor');
    $group   = WorkspaceGroup::factory()->create(['name' => 'Security', 'position' => 0]);
    $grouped = Workspace::factory()->create(['group_id' => $group->id, 'position' => 5]);

    $past = Carbon::parse('2026-01-01 00:00:00');
    DB::table('workspaces')->where('id', $grouped->id)->update(['updated_at' => $past]);

    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'group', 'id' => $group->id],
        ['type' => 'workspace', 'id' => $grouped->id],
    ]])->assertRedirect();

    expect($grouped->fresh()->group_id)->toBeNull()
        ->and($grouped->fresh()->updated_at->equalTo($past))->toBeTrue();

    $event = AuditEvent::firstWhere('event', 'workspace.moved');
    expect($event->context)->toMatchArray(['from_group' => 'Security', 'to_group' => null]);
});

test('one save refiling several workspaces records one move event each', function () {
    login(role: 'editor');
    $a  = WorkspaceGroup::factory()->create(['name' => 'Alpha', 'position' => 0]);
    $b  = WorkspaceGroup::factory()->create(['name' => 'Beta', 'position' => 1]);
    $w1 = Workspace::factory()->create(['name' => 'One', 'group_id' => $a->id, 'position' => 0]);
    $w2 = Workspace::factory()->create(['name' => 'Two', 'group_id' => null, 'position' => 0]);

    // w1: Alpha -> Beta; w2: loose -> Beta. Two moves, one atomic save.
    $this->patch('/workspaces/top-level-order', [
        'items'  => [['type' => 'group', 'id' => $a->id], ['type' => 'group', 'id' => $b->id]],
        'groups' => [
            ['id' => $a->id, 'members' => []],
            ['id' => $b->id, 'members' => [$w1->id, $w2->id]],
        ],
    ])->assertRedirect();

    expect($w1->fresh()->group_id)->toBe($b->id)
        ->and($w2->fresh()->group_id)->toBe($b->id)
        ->and(AuditEvent::where('event', 'workspace.moved')->count())->toBe(2);

    $contexts = AuditEvent::where('event', 'workspace.moved')->get()->pluck('context');
    expect($contexts->firstWhere('name', 'One'))->toMatchArray(['from_group' => 'Alpha', 'to_group' => 'Beta']);
    expect($contexts->firstWhere('name', 'Two'))->toMatchArray(['from_group' => null, 'to_group' => 'Beta']);
});

test('reordering the top level is structural and does not bump workspace updated_at', function () {
    login(role: 'editor');
    $w = Workspace::factory()->create(['group_id' => null, 'position' => 5]);

    $past = Carbon::parse('2026-01-01 00:00:00');
    DB::table('workspaces')->where('id', $w->id)->update(['updated_at' => $past]);

    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'workspace', 'id' => $w->id],
    ]])->assertRedirect();

    expect($w->fresh()->position)->toBe(0)
        ->and($w->fresh()->updated_at->equalTo($past))->toBeTrue();
});

test('the top-level reorder is not audited (layout noise, like sibling reorders)', function () {
    login(role: 'editor');
    $g = WorkspaceGroup::factory()->create(['position' => 1]);
    $w = Workspace::factory()->create(['group_id' => null, 'position' => 0]);

    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'workspace', 'id' => $w->id],
        ['type' => 'group',     'id' => $g->id],
    ]])->assertRedirect();

    expect(AuditEvent::whereIn('event', ['workspace.moved', 'group.renamed'])->count())->toBe(0);
});

test('a viewer cannot reorder the top level', function () {
    login(role: 'viewer');
    $g = WorkspaceGroup::factory()->create(['position' => 0]);

    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'group', 'id' => $g->id],
    ]])->assertForbidden();
});

test('the top-level reorder rejects an unknown group id', function () {
    login(role: 'editor');

    $this->patch('/workspaces/top-level-order', ['items' => [
        ['type' => 'group', 'id' => 99999],
    ]])->assertStatus(422);
});

test('a newly created group sorts to the top of the order', function () {
    login();
    // An existing group and an ungrouped workspace already occupy the top level.
    $existing = WorkspaceGroup::factory()->create(['position' => 0]);
    Workspace::factory()->create(['group_id' => null, 'position' => 0]);

    $this->post('/workspaces/groups', ['name' => 'Newest'])->assertRedirect();

    $new = WorkspaceGroup::firstWhere('name', 'Newest');
    // Below every current top-level position, so it sorts first — not last by id.
    expect($new->position)->toBeLessThan($existing->position);
    expect($new->position)->toBe(WorkspaceGroup::min('position'));
});
