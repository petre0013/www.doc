<?php

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;

// ── Access ────────────────────────────────────────────────────────────────────

test('non-admins are forbidden from the audit page', function () {
    login(role: 'editor');

    $this->get('/admin/audit')->assertForbidden();
});

test('an admin can view the audit page with events', function () {
    $admin = login();
    Audit::record('document.updated', null, ['title' => 'A page']);

    $this->get('/admin/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Audit')
            ->has('events.data', 1)
            ->where('events.data.0.event', 'document.updated')
            ->where('events.data.0.user.id', $admin->id));
});

test('the audit page filters by event namespace and user', function () {
    $admin = login();
    Audit::record('document.updated');
    Audit::record('workspace.created');

    $this->get('/admin/audit?event=workspace')
        ->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.event', 'workspace.created'));

    $other = User::factory()->create();
    Audit::record('auth.login', null, [], $other->id);

    $this->get("/admin/audit?user={$other->id}")
        ->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.event', 'auth.login'));
});

// ── Document lifecycle ────────────────────────────────────────────────────────

test('creating and editing a page write audit events', function () {
    $user = login(role: 'editor');
    $workspace = Workspace::factory()->create();

    $this->post('/documents', [
        'workspace_id' => $workspace->id,
        'title'        => 'Runbook',
    ]);
    $document = Document::firstWhere('title', 'Runbook');

    $created = AuditEvent::firstWhere('event', 'document.created');
    expect($created)->not->toBeNull()
        ->and($created->user_id)->toBe($user->id)
        ->and($created->auditable_id)->toBe($document->id)
        ->and($created->workspace_id)->toBe($workspace->id);

    $this->patch("/documents/{$document->id}", ['title' => 'Runbook v2']);

    expect(AuditEvent::where('event', 'document.updated')->count())->toBe(1);
});

test('structural moves are not logged as edits', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create();
    $parent    = Document::factory()->for($workspace)->create();
    AuditEvent::query()->delete(); // query-builder delete: clear setup noise

    $this->patch("/documents/{$document->id}/move", [
        'parent_id'    => $parent->id,
        'workspace_id' => $workspace->id,
    ]);

    expect(AuditEvent::where('event', 'document.moved')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'document.updated')->count())->toBe(0);
});

test('trash, restore and purge write one event per user action', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create();
    Document::factory()->for($workspace)->create(['parent_id' => $document->id]);

    $this->delete("/documents/{$document->id}");
    expect(AuditEvent::where('event', 'document.trashed')->count())->toBe(1);

    $this->post("/trash/documents/{$document->id}/restore");
    expect(AuditEvent::where('event', 'document.restored')->count())->toBe(1);

    $this->delete("/documents/{$document->id}");
    $this->delete("/trash/documents/{$document->id}");
    $purged = AuditEvent::firstWhere('event', 'document.purged');
    expect($purged)->not->toBeNull()
        ->and($purged->context['document_id'])->toBe($document->id);
});

test('restoring a version records the intent with the snapshot id', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create();
    $document->update(['title' => 'Second title']); // snapshots a version
    $version = DocumentVersion::firstWhere('document_id', $document->id);

    $this->post("/documents/{$document->id}/versions/{$version->id}/restore");

    $event = AuditEvent::firstWhere('event', 'document.version_restored');
    expect($event)->not->toBeNull()
        ->and($event->context['version_id'])->toBe($version->id);
});

// ── Workspaces ─────────────────────────────────────────────────────────────────

test('workspace rename and description edits are audited distinctly', function () {
    login();
    AuditEvent::query()->delete(); // query-builder delete: clear setup noise
    $workspace = Workspace::factory()->create(['name' => 'Old', 'description' => null]);

    // Description-only edit → workspace.updated, with from/to.
    $this->patch("/workspaces/{$workspace->id}", ['name' => 'Old', 'description' => 'Networking docs']);
    $updated = AuditEvent::firstWhere('event', 'workspace.updated');
    expect($updated)->not->toBeNull()
        ->and($updated->auditable_id)->toBe($workspace->id)
        ->and($updated->context['from'])->toBeNull()
        ->and($updated->context['to'])->toBe('Networking docs');
    expect(AuditEvent::where('event', 'workspace.renamed')->count())->toBe(0);

    // A rename (with a simultaneous description change) → one workspace.renamed
    // that carries the description delta alongside; no second event.
    $this->patch("/workspaces/{$workspace->id}", ['name' => 'New', 'description' => 'Infra docs']);
    $renamed = AuditEvent::firstWhere('event', 'workspace.renamed');
    expect($renamed)->not->toBeNull()
        ->and($renamed->context['from'])->toBe('Old')
        ->and($renamed->context['to'])->toBe('New')
        ->and($renamed->context['description_from'])->toBe('Networking docs')
        ->and($renamed->context['description_to'])->toBe('Infra docs');
    expect(AuditEvent::where('event', 'workspace.updated')->count())->toBe(1);

    // A no-op save logs nothing new.
    $this->patch("/workspaces/{$workspace->id}", ['name' => 'New', 'description' => 'Infra docs']);
    expect(AuditEvent::whereIn('event', ['workspace.renamed', 'workspace.updated'])->count())->toBe(2);
});

// ── Tags ──────────────────────────────────────────────────────────────────────

test('tag create, rename and delete are audited', function () {
    $user = login(); // delete is admin-only
    AuditEvent::query()->delete(); // query-builder delete: clear setup noise

    $this->post('/tags', ['name' => 'Networking']);
    $tag = Tag::firstWhere('name', 'Networking');
    $created = AuditEvent::firstWhere('event', 'tag.created');
    expect($created)->not->toBeNull()
        ->and($created->user_id)->toBe($user->id)
        ->and($created->auditable_id)->toBe($tag->id)
        ->and($created->context['name'])->toBe('Networking');

    $this->patch("/tags/{$tag->id}", ['name' => 'Infrastructure']);
    $renamed = AuditEvent::firstWhere('event', 'tag.renamed');
    expect($renamed)->not->toBeNull()
        ->and($renamed->context['from'])->toBe('Networking')
        ->and($renamed->context['to'])->toBe('Infrastructure');

    // A no-op update (same name) must not log a rename.
    $this->patch("/tags/{$tag->id}", ['name' => 'Infrastructure']);
    expect(AuditEvent::where('event', 'tag.renamed')->count())->toBe(1);

    $this->delete("/tags/{$tag->id}");
    $deleted = AuditEvent::firstWhere('event', 'tag.deleted');
    expect($deleted)->not->toBeNull()
        ->and($deleted->auditable_id)->toBeNull() // subject destroyed — identity in context
        ->and($deleted->context['tag_id'])->toBe($tag->id)
        ->and($deleted->context['name'])->toBe('Infrastructure');
});

test('a tags-only page save records the tag delta', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create();
    $alpha = Tag::factory()->create(['name' => 'Alpha']);
    $beta  = Tag::factory()->create(['name' => 'Beta']);
    $document->tags()->sync([$alpha->id]);
    AuditEvent::query()->delete(); // query-builder delete: clear setup noise

    $this->patch("/documents/{$document->id}", ['tags' => [$beta->id]]);

    $event = AuditEvent::firstWhere('event', 'document.tags_changed');
    expect($event)->not->toBeNull()
        ->and($event->auditable_id)->toBe($document->id)
        ->and($event->context['from'])->toBe('Alpha')
        ->and($event->context['to'])->toBe('Beta')
        ->and(AuditEvent::where('event', 'document.updated')->count())->toBe(0);
});

test('a save that edits content and tags together logs one document.updated only', function () {
    login(role: 'editor');
    $workspace = Workspace::factory()->create();
    $document  = Document::factory()->for($workspace)->create();
    $tag = Tag::factory()->create();
    AuditEvent::query()->delete(); // query-builder delete: clear setup noise

    $this->patch("/documents/{$document->id}", [
        'title' => 'Retitled',
        'tags'  => [$tag->id],
    ]);

    expect(AuditEvent::where('event', 'document.updated')->count())->toBe(1)
        ->and(AuditEvent::where('event', 'document.tags_changed')->count())->toBe(0);
});

// ── Auth ──────────────────────────────────────────────────────────────────────

test('login, failed login and logout are audited', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    $failed = AuditEvent::firstWhere('event', 'auth.login_failed');
    expect($failed)->not->toBeNull()
        ->and($failed->user_id)->toBeNull()
        ->and($failed->context['email'])->toBe($user->email);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
    expect(AuditEvent::firstWhere('event', 'auth.login')?->user_id)->toBe($user->id);

    $this->post('/logout');
    expect(AuditEvent::firstWhere('event', 'auth.logout')?->user_id)->toBe($user->id);
});

// ── Admin users ───────────────────────────────────────────────────────────────

test('role changes are audited with old and new role', function () {
    login();
    $other = User::factory()->create();
    $other->assignRole('viewer');

    $this->patch("/admin/users/{$other->id}", ['role' => 'editor']);

    $event = AuditEvent::firstWhere('event', 'user.role_changed');
    expect($event)->not->toBeNull()
        ->and($event->context['from'])->toBe('viewer')
        ->and($event->context['to'])->toBe('editor');
});

test('deleting a user keeps their identity in the event context', function () {
    login();
    $other = User::factory()->create();
    $other->assignRole('viewer');

    $this->delete("/admin/users/{$other->id}");

    $event = AuditEvent::firstWhere('event', 'user.deleted');
    expect($event->context['email'])->toBe($other->email)
        ->and(User::find($other->id))->toBeNull();
});

// ── Immutability + retention ──────────────────────────────────────────────────

test('audit events cannot be updated or deleted through the model', function () {
    login();
    $event = Audit::record('document.updated');

    expect(fn () => $event->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('audit:prune removes only events past the retention window', function () {
    login();
    Audit::record('document.updated');
    AuditEvent::query()->update(['created_at' => now()->subDays(400)]); // bypasses model guard on purpose
    Audit::record('document.created');

    $this->artisan('audit:prune')->assertExitCode(0);

    expect(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::first()->event)->toBe('document.created');
});

test('a restore merges the archived audit trail instead of replacing it', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    login();

    $old = Audit::record('document.created'); // exists at backup time

    $backup = \App\Models\Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(\App\Services\Backup\BackupService::class)->run($backup->fresh());

    $new = Audit::record('workspace.created'); // recorded AFTER the backup

    app(\App\Services\Backup\RestoreService::class)->restore($backup->fresh());

    // The archived event is back (not duplicated), and the newer one survived
    // the wipe — the trail is append-only even across restores.
    expect(AuditEvent::whereKey($old->id)->exists())->toBeTrue()
        ->and(AuditEvent::whereKey($new->id)->exists())->toBeTrue()
        ->and(AuditEvent::count())->toBe(2);
});

test('audit:prune respects the retention_days setting', function () {
    login();
    \App\Models\Setting::put('audit', ['retention_days' => 30]);
    Audit::record('document.updated');
    AuditEvent::query()->update(['created_at' => now()->subDays(31)]);

    $this->artisan('audit:prune')->assertExitCode(0);

    expect(AuditEvent::count())->toBe(0);
});

test('audit event ip accessor normalizes mapped ipv4 and localhost addresses', function () {
    $event1 = new AuditEvent(['ip' => '::1']);
    expect($event1->ip)->toBe('127.0.0.1');

    $event2 = new AuditEvent(['ip' => '::ffff:192.168.1.5']);
    expect($event2->ip)->toBe('192.168.1.5');

    $event3 = new AuditEvent(['ip' => '2a01:4b00:8a1a:2800:1999:1212:1222:2']);
    expect($event3->ip)->toBe('2a01:4b00:8a1a:2800:1999:1212:1222:2');

    $event4 = new AuditEvent(['ip' => '10.0.0.1']);
    expect($event4->ip)->toBe('10.0.0.1');

    $event5 = new AuditEvent(['ip' => null]);
    expect($event5->ip)->toBeNull();
});
