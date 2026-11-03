<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

function pivotRow(User $user, Document $document): ?object
{
    return DB::table('document_user')
        ->where('user_id', $user->id)
        ->where('document_id', $document->id)
        ->first();
}

// ── Starring ──────────────────────────────────────────────────────────────────

test('starring toggles on and off, for any role, without audit events', function () {
    $user = login(role: 'viewer'); // starring is personal — even viewers may
    $document = Document::factory()->for(Workspace::factory())->create();
    AuditEvent::query()->delete(); // clear setup noise

    $this->post("/documents/{$document->id}/star")->assertRedirect();
    expect(pivotRow($user, $document)->starred_at)->not->toBeNull();

    $this->post("/documents/{$document->id}/star")->assertRedirect();
    expect(pivotRow($user, $document)->starred_at)->toBeNull();

    // Personal navigation state is deliberately not audited.
    expect(AuditEvent::count())->toBe(0);
});

test('stars are per user', function () {
    $document = Document::factory()->for(Workspace::factory())->create();

    $alice = login(role: 'editor');
    $this->post("/documents/{$document->id}/star");

    $bob = login(role: 'editor');
    expect(pivotRow($alice, $document)->starred_at)->not->toBeNull()
        ->and(pivotRow($bob, $document))->toBeNull();
});

// ── Recents ───────────────────────────────────────────────────────────────────

test('viewing a page records a throttled last_viewed_at stamp', function () {
    $user = login(role: 'viewer');
    $document = Document::factory()->for(Workspace::factory())->create();

    $this->get("/documents/{$document->id}")->assertOk();
    $first = pivotRow($user, $document)->last_viewed_at;
    expect($first)->not->toBeNull();

    // A fresh stamp is left alone — ordinary navigation isn't a write per view.
    $this->travel(2)->minutes();
    $this->get("/documents/{$document->id}");
    expect(pivotRow($user, $document)->last_viewed_at)->toBe($first);

    // Past the 5-minute window the stamp moves.
    $this->travel(10)->minutes();
    $this->get("/documents/{$document->id}");
    expect(pivotRow($user, $document)->last_viewed_at)->not->toBe($first);
});

test('viewing a page never touches the document row itself', function () {
    login(role: 'editor');
    $document = Document::factory()->for(Workspace::factory())->create();
    $updatedAt = $document->updated_at;
    $version   = $document->version;

    $this->get("/documents/{$document->id}")->assertOk();

    $document->refresh();
    expect($document->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($document->version)->toBe($version);
});

// ── Lists on the workspaces overview ─────────────────────────────────────────

test('the workspaces index lists starred and recently viewed pages, excluding trashed ones', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $starred = Document::factory()->for($workspace)->create(['title' => 'Starred page']);
    $viewed  = Document::factory()->for($workspace)->create(['title' => 'Viewed page']);
    $trashed = Document::factory()->for($workspace)->create(['title' => 'Trashed page']);

    $this->post("/documents/{$starred->id}/star");
    $this->post("/documents/{$trashed->id}/star");
    $this->get("/documents/{$viewed->id}");
    $this->get("/documents/{$trashed->id}");

    $trashed->trashSubtree();

    $this->get('/workspaces')
        ->assertInertia(fn ($page) => $page
            ->has('starred', 1)
            ->where('starred.0.title', 'Starred page')
            // Starred/trashed pages dropped; the two viewed ones remain minus
            // the trashed one.
            ->where('recentlyViewed.0.title', fn ($t) => $t !== 'Trashed page')
            ->has('recentlyViewed', 1)
            ->where('recentlyViewed.0.title', 'Viewed page'));
});

// ── Cleanup semantics ─────────────────────────────────────────────────────────

test('pivot rows disappear with a purged document and a deleted user', function () {
    $user = login();
    $document = Document::factory()->for(Workspace::factory())->create();
    $this->post("/documents/{$document->id}/star");
    expect(pivotRow($user, $document))->not->toBeNull();

    // Purge: FK cascade removes the rows.
    $document->forceDeleteSubtree();
    expect(pivotRow($user, $document))->toBeNull();

    // User delete: same, from the other side.
    $other = Document::factory()->for(Workspace::factory())->create();
    $this->post("/documents/{$other->id}/star");
    $userId = $user->id;
    $user->delete();
    expect(DB::table('document_user')->where('user_id', $userId)->count())->toBe(0);
});
