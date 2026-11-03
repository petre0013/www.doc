<?php

use App\Models\Document;
use App\Models\Workspace;

test('children endpoint lists immediate children in order', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 1, 'title' => 'Second']);
    Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 0, 'title' => 'First']);

    $this->getJson("/documents/{$parent->id}/children")
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.title', 'First')
        ->assertJsonPath('1.title', 'Second');
});

test('a node can be moved under a new parent', function () {
    login();
    $workspace = Workspace::factory()->create();
    $a = Document::factory()->create(['workspace_id' => $workspace->id]);
    $b = Document::factory()->create(['workspace_id' => $workspace->id]);

    $this->patch("/documents/{$b->id}/move", ['parent_id' => $a->id])->assertRedirect();

    expect($b->fresh()->parent_id)->toBe($a->id);
});

test('a move can re-parent and order the destination siblings in one request', function () {
    login();
    $workspace = Workspace::factory()->create();
    $newParent = Document::factory()->create(['workspace_id' => $workspace->id]);
    // Two pages already nested under the destination parent.
    $existingA = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $newParent->id, 'position' => 0]);
    $existingB = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $newParent->id, 'position' => 1]);
    // A root page being dragged in to become the middle child.
    $dragged = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);

    $this->patch("/documents/{$dragged->id}/move", [
        'parent_id' => $newParent->id,
        'order' => [$existingA->id, $dragged->id, $existingB->id],
    ])->assertRedirect();

    expect($dragged->fresh()->parent_id)->toBe($newParent->id);
    // Positions follow the given order exactly.
    expect($existingA->fresh()->position)->toBe(0);
    expect($dragged->fresh()->position)->toBe(1);
    expect($existingB->fresh()->position)->toBe(2);
});

test('moving a page to another workspace carries its subtree along', function () {
    login();
    $from = Workspace::factory()->create();
    $to = Workspace::factory()->create();

    $parent = Document::factory()->create(['workspace_id' => $from->id]);
    $child = Document::factory()->create(['workspace_id' => $from->id, 'parent_id' => $parent->id]);
    $grandchild = Document::factory()->create(['workspace_id' => $from->id, 'parent_id' => $child->id]);

    $this->patch("/documents/{$parent->id}/move", ['workspace_id' => $to->id, 'parent_id' => null])
        ->assertRedirect();

    // The moved page sits at the new workspace root, and the whole subtree follows.
    expect($parent->fresh()->workspace_id)->toBe($to->id);
    expect($parent->fresh()->parent_id)->toBeNull();
    expect($child->fresh()->workspace_id)->toBe($to->id);
    expect($grandchild->fresh()->workspace_id)->toBe($to->id);
    // Nesting within the subtree is preserved.
    expect($child->fresh()->parent_id)->toBe($parent->id);
});

test('the update endpoint will not re-parent a document', function () {
    login();
    $workspace = Workspace::factory()->create();
    $a = Document::factory()->create(['workspace_id' => $workspace->id]);
    $b = Document::factory()->create(['workspace_id' => $workspace->id]);

    // parent_id is silently ignored on a plain update — re-parenting is move()'s
    // job (the only path with a cycle guard). The title still updates.
    $this->patch("/documents/{$b->id}", ['parent_id' => $a->id, 'title' => 'Renamed'])
        ->assertRedirect();

    expect($b->fresh()->parent_id)->toBeNull()
        ->and($b->fresh()->title)->toBe('Renamed');
});

test('ancestors() terminates on cyclic data instead of looping forever', function () {
    login();
    $workspace = Workspace::factory()->create();
    $a = Document::factory()->create(['workspace_id' => $workspace->id]);
    $b = Document::factory()->create(['workspace_id' => $workspace->id]);

    // Force a corrupt A<->B cycle straight in the DB, bypassing the guards.
    Document::withoutTimestamps(function () use ($a, $b) {
        Document::whereKey($a->id)->update(['parent_id' => $b->id]);
        Document::whereKey($b->id)->update(['parent_id' => $a->id]);
    });

    // The visited-set bound means this returns quickly rather than hanging.
    $chain = $a->fresh()->ancestors();

    expect($chain)->toBeArray()
        ->and(count($chain))->toBeLessThanOrEqual(2);
});

test('a move only repositions actual children of the new parent', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id, 'position' => 0]);
    $dragged = Document::factory()->create(['workspace_id' => $workspace->id]);
    // An unrelated root page whose id is smuggled into the order array.
    $unrelated = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 5]);

    $this->patch("/documents/{$dragged->id}/move", [
        'parent_id' => $parent->id,
        'order' => [$child->id, $dragged->id, $unrelated->id],
    ])->assertRedirect();

    expect($dragged->fresh()->parent_id)->toBe($parent->id);
    expect($child->fresh()->position)->toBe(0);
    expect($dragged->fresh()->position)->toBe(1);
    // The unrelated page is NOT a child of $parent, so its position is untouched.
    expect($unrelated->fresh()->position)->toBe(5);
});

test('a node cannot be moved into its own descendant', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $child = Document::factory()->create(['workspace_id' => $workspace->id, 'parent_id' => $parent->id]);

    $this->patch("/documents/{$parent->id}/move", ['parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');

    expect($parent->fresh()->parent_id)->toBeNull();
});

test('siblings can be reordered', function () {
    login();
    $workspace = Workspace::factory()->create();
    $first = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    $second = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);
    $stamp = $first->updated_at;

    $this->patch('/documents/reorder', ['ids' => [$second->id, $first->id]])->assertRedirect();

    expect($second->fresh()->position)->toBe(0);
    expect($first->fresh()->position)->toBe(1);
    // Reordering is structural — it must not bump updated_at.
    expect($first->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

test('the tree endpoint saves nesting and positions in one batch', function () {
    login();
    $workspace = Workspace::factory()->create();
    $a = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    $b = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);
    $c = Document::factory()->create(['workspace_id' => $workspace->id, 'position' => 2]);
    $stamp = $a->updated_at;

    // Nest b under a, keep c at the root, and flip the root order.
    $this->patch("/workspaces/{$workspace->id}/tree", ['nodes' => [
        ['id' => $c->id, 'parent_id' => null,    'position' => 0],
        ['id' => $a->id, 'parent_id' => null,    'position' => 1],
        ['id' => $b->id, 'parent_id' => $a->id,  'position' => 0],
    ]])->assertRedirect();

    expect($b->fresh()->parent_id)->toBe($a->id);
    expect($c->fresh()->position)->toBe(0);
    expect($a->fresh()->position)->toBe(1);
    // Structural change must not bump the activity timestamp.
    expect($a->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

test('the tree endpoint rejects a page from another workspace', function () {
    login();
    $workspace = Workspace::factory()->create();
    $mine      = Document::factory()->create(['workspace_id' => $workspace->id]);
    $foreign   = Document::factory()->create(); // a different workspace

    $this->patch("/workspaces/{$workspace->id}/tree", ['nodes' => [
        ['id' => $mine->id,    'parent_id' => null, 'position' => 0],
        ['id' => $foreign->id, 'parent_id' => null, 'position' => 1],
    ]])->assertSessionHasErrors('nodes');

    expect($foreign->fresh()->workspace_id)->not->toBe($workspace->id);
});

test('the tree endpoint rejects a cycle', function () {
    login();
    $workspace = Workspace::factory()->create();
    $a = Document::factory()->create(['workspace_id' => $workspace->id]);
    $b = Document::factory()->create(['workspace_id' => $workspace->id]);

    // a under b and b under a — a cycle that must be refused.
    $this->patch("/workspaces/{$workspace->id}/tree", ['nodes' => [
        ['id' => $a->id, 'parent_id' => $b->id, 'position' => 0],
        ['id' => $b->id, 'parent_id' => $a->id, 'position' => 0],
    ]])->assertSessionHasErrors('nodes');

    expect($a->fresh()->parent_id)->toBeNull();
    expect($b->fresh()->parent_id)->toBeNull();
});

test('a cross-workspace move carries trashed descendants too, so a later restore lands in the right workspace', function () {
    login();
    $from = Workspace::factory()->create();
    $to = Workspace::factory()->create();

    $parent = Document::factory()->create(['workspace_id' => $from->id]);
    $trashedChild = Document::factory()->create(['workspace_id' => $from->id, 'parent_id' => $parent->id]);
    $trashedChild->delete();

    $this->patch("/documents/{$parent->id}/move", ['workspace_id' => $to->id])->assertRedirect();

    // The trashed child followed its parent; restoring it from Trash now puts
    // it back inside the parent's (new) workspace instead of orphaning it.
    expect(Document::withTrashed()->find($trashedChild->id)->workspace_id)->toBe($to->id);
});

test('a page cannot be moved under a trashed parent', function () {
    login();
    $workspace = Workspace::factory()->create();
    $parent = Document::factory()->create(['workspace_id' => $workspace->id]);
    $page = Document::factory()->create(['workspace_id' => $workspace->id]);
    $parent->delete();

    $this->patch("/documents/{$page->id}/move", ['parent_id' => $parent->id])
        ->assertSessionHasErrors('parent_id');

    expect($page->fresh()->parent_id)->toBeNull();
});

test('a cross-workspace move re-slugs a page that would collide in the destination', function () {
    login();
    $source = Workspace::factory()->create();
    $dest   = Workspace::factory()->create();

    // Same title in each workspace → same slug, which is legal because slugs are
    // unique PER workspace. The move must not let both own "shared-title" in dest.
    $incumbent = Document::factory()->create(['workspace_id' => $dest->id, 'title' => 'Shared Title']);
    $mover     = Document::factory()->create(['workspace_id' => $source->id, 'title' => 'Shared Title']);

    expect($mover->slug)->toBe($incumbent->slug); // both "shared-title"

    $this->patch("/documents/{$mover->id}/move", ['workspace_id' => $dest->id])
        ->assertRedirect();

    $mover->refresh();

    expect($mover->workspace_id)->toBe($dest->id)
        ->and($incumbent->fresh()->slug)->toBe($incumbent->slug)  // incumbent untouched
        ->and($mover->slug)->not->toBe($incumbent->slug);         // mover got a unique slug
});

test('a cross-workspace move re-slugs colliding descendants too', function () {
    login();
    $source = Workspace::factory()->create();
    $dest   = Workspace::factory()->create();

    // A child that will collide with an existing page in the destination.
    $incumbent = Document::factory()->create(['workspace_id' => $dest->id, 'title' => 'Child Page']);
    $parent    = Document::factory()->create(['workspace_id' => $source->id, 'title' => 'Parent Page']);
    $child     = Document::factory()->create([
        'workspace_id' => $source->id,
        'parent_id'    => $parent->id,
        'title'        => 'Child Page',
    ]);

    expect($child->slug)->toBe($incumbent->slug);

    $this->patch("/documents/{$parent->id}/move", ['workspace_id' => $dest->id])
        ->assertRedirect();

    $child->refresh();

    expect($child->workspace_id)->toBe($dest->id)
        ->and($child->slug)->not->toBe($incumbent->slug);
});
