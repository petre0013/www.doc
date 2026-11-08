<?php

use App\Models\AuditEvent;
use App\Models\Attachment;
use App\Models\Backup;
use App\Models\ConversionJob;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function restrictedPage(array $members = []): Document
{
    $workspace = Workspace::factory()->create(['name' => 'Incident Response', 'restricted' => true]);
    $workspace->members()->sync(collect($members)->pluck('id'));

    return Document::factory()->for($workspace)->create([
        'title'   => 'Secretplan Report',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Secretplan details']]],
        ]],
    ]);
}

test('non-members get a 404 on every direct route into a restricted workspace', function (string $method, string $uri) {
    login(role: 'editor');
    $page = restrictedPage();
    $job = ConversionJob::factory()->create(['document_id' => $page->id, 'direction' => 'export', 'format' => 'pdf', 'status' => 'done']);
    $version = $page->versions()->first();
    $attachment = Attachment::factory()->for($page)->create();

    $uri = strtr($uri, ['{w}' => $page->workspace_id, '{d}' => $page->id, '{v}' => $version->id, '{j}' => $job->id, '{a}' => $attachment->id]);

    $this->call($method, $uri, ['title' => 'x', 'format' => 'pdf', 'name' => 'x'])->assertNotFound();
})->with([
    ['GET', '/workspaces/{w}'],
    ['PATCH', '/workspaces/{w}'],
    ['GET', '/documents/{d}'],
    ['GET', '/documents/{d}/preview'],
    ['GET', '/documents/{d}/children'],
    ['POST', '/documents/{d}/star'],
    ['PATCH', '/documents/{d}/rename'],
    ['GET', '/documents/{d}/versions'],
    ['GET', '/documents/{d}/versions/{v}'],
    ['GET', '/documents/{d}/versions/compare'],
    ['POST', '/documents/{d}/versions/{v}/restore'],
    ['POST', '/documents/{d}/exports'],
    ['GET', '/documents/{d}/exports/{j}?download=1'],
    ['GET', '/imports/{j}'],
    ['GET', '/documents/{d}/attachments/{a}'],
    ['POST', '/documents/{d}/template'],
    ['POST', '/workspaces/{w}/imports'],
    ['POST', '/workspaces/{w}/folders'],
]);

test('page-against-page compare cannot read a restricted page', function () {
    login(role: 'viewer');
    $open = Document::factory()->create();
    $page = restrictedPage();

    $this->get("/documents/compare?left={$open->id}&right={$page->id}")->assertNotFound();
});

test('folders in a restricted workspace cannot be changed by non-members', function () {
    login(role: 'editor');
    $folder = DocumentFolder::factory()->for(restrictedPage()->workspace)->create();

    $this->patch("/folders/{$folder->id}", ['name' => 'x'])->assertNotFound();
    $this->delete("/folders/{$folder->id}")->assertNotFound();
});

test('members can read, and their global role still decides editing', function () {
    $viewer = login(role: 'viewer');
    $page = restrictedPage([$viewer]);

    $this->get("/documents/{$page->id}")->assertOk();
    $this->patch("/documents/{$page->id}/rename", ['title' => 'x'])->assertForbidden();
});

test('admins can open restricted workspaces without being members', function () {
    login();

    $this->get('/documents/' . restrictedPage()->id)->assertOk();
});

test('unrestricted workspaces are unaffected', function () {
    login(role: 'viewer');

    $this->get('/documents/' . Document::factory()->create()->id)->assertOk();
});

test('search does not return restricted pages, excerpts or workspaces to non-members', function () {
    $user = login(role: 'viewer');
    $page = restrictedPage();

    $this->get('/search?q=Secretplan')->assertInertia(fn (Assert $p) => $p->where('results', []));
    $this->get('/search?q=Incident')->assertInertia(fn (Assert $p) => $p->where('results', []));

    $page->workspace->members()->attach($user);
    $this->get('/search?q=Secretplan')->assertInertia(fn (Assert $p) => $p->has('results', 1));
});

test('the workspaces index hides restricted workspaces and their pages', function () {
    $user = login(role: 'viewer');
    $page = restrictedPage();
    DB::table('document_user')->insert(['user_id' => $user->id, 'document_id' => $page->id, 'starred_at' => now(), 'last_viewed_at' => now()]);

    $this->get('/workspaces')->assertInertia(fn (Assert $p) => $p
        ->has('workspaces', 0)
        ->has('recent', 0)
        ->has('starred', 0)
        ->has('recentlyViewed', 0));
});

test('tags hide restricted pages from lists and counts', function () {
    login(role: 'viewer');
    $tag = Tag::factory()->create();
    restrictedPage()->tags()->attach($tag);

    $this->get("/tags/{$tag->id}")->assertInertia(fn (Assert $p) => $p->has('groups', 0));
    $this->get('/tags')->assertInertia(fn (Assert $p) => $p->where('tags.0.documents_count', 0));
});

test('an open page does not reveal restricted links, backlinks or picker entries', function () {
    login(role: 'viewer');
    $open = Document::factory()->create();
    $page = restrictedPage();
    Link::create(['source_document_id' => $page->id, 'target_document_id' => $open->id, 'target_title' => $open->title, 'context' => 'secret context']);
    Link::create(['source_document_id' => $open->id, 'target_document_id' => $page->id, 'target_title' => 'Secretplan Report', 'context' => 'x']);

    $this->get("/documents/{$open->id}")->assertInertia(fn (Assert $p) => $p
        ->has('backlinks', 0)
        ->where('document.outgoing_links.0.target', null)
        ->where('allDocuments', fn ($docs) => collect($docs)->pluck('id')->doesntContain($page->id))
        ->where('workspaces', fn ($list) => collect($list)->pluck('id')->doesntContain($page->workspace_id)));
});

test('non-members cannot create pages in or move pages into a restricted workspace', function () {
    login(role: 'editor');
    $restricted = restrictedPage()->workspace;
    $open = Document::factory()->create();

    $this->post('/documents', ['title' => 'x', 'workspace_id' => $restricted->id])->assertNotFound();
    $this->patch("/documents/{$open->id}/move", ['workspace_id' => $restricted->id])->assertNotFound();
});

test('only admins can move a page out of a restricted workspace', function () {
    $member = login(role: 'editor');
    $page = restrictedPage([$member]);
    $open = Workspace::factory()->create();

    $this->patch("/documents/{$page->id}/move", ['workspace_id' => $open->id])->assertForbidden();

    login();
    $this->patch("/documents/{$page->id}/move", ['workspace_id' => $open->id])->assertSessionHasNoErrors();
    expect($page->fresh()->workspace_id)->toBe($open->id);
});

test('only admins can change access', function () {
    $editor = login(role: 'editor');
    $workspace = Workspace::factory()->create();

    $this->put("/workspaces/{$workspace->id}/access", ['restricted' => true, 'member_ids' => [$editor->id]])->assertForbidden();

    expect($workspace->fresh()->restricted)->toBeFalse();
});

test('access changes are saved and audited with names', function () {
    login();
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();

    $this->put("/workspaces/{$workspace->id}/access", ['restricted' => true, 'member_ids' => [$member->id]])->assertSessionHasNoErrors();

    $event = AuditEvent::firstWhere('event', 'workspace.access_changed');
    expect($workspace->fresh()->restricted)->toBeTrue()
        ->and($event->context['added'])->toBe([$member->name])
        ->and($event->workspace_id)->toBe($workspace->id);
});

test('saving unchanged access is not audited', function () {
    login();
    $workspace = Workspace::factory()->create();

    $this->put("/workspaces/{$workspace->id}/access", ['restricted' => false, 'member_ids' => []]);

    expect(AuditEvent::where('event', 'workspace.access_changed')->exists())->toBeFalse();
});

test('non-admins do not receive the member list', function () {
    $member = login(role: 'editor');

    $this->get('/workspaces/' . restrictedPage([$member])->workspace_id)
        ->assertInertia(fn (Assert $p) => $p->where('access', null));
});

test('a backup restore keeps the restriction and its members', function () {
    Storage::fake('local');
    $member = login();
    $page = restrictedPage([$member]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);
    app(RestoreService::class)->restore($backup->fresh());

    $workspace = Workspace::find($page->workspace_id);
    expect($workspace->restricted)->toBeTrue()
        ->and($workspace->members()->pluck('users.id')->all())->toBe([$member->id]);
});
