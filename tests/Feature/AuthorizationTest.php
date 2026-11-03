<?php

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;

// Guest vs. authenticated boundary. The per-role rules (viewer/editor/admin)
// are exercised in RolePermissionTest.

test('guests cannot reach any resource route', function (string $method, string $uri) {
    $this->call($method, $uri)->assertRedirect('/login');
})->with([
    ['get', '/workspaces'],
    ['post', '/workspaces'],
    ['get', '/tags'],
    ['post', '/documents'],
    ['patch', '/documents/reorder'],
]);

test('an authenticated user passes the policy for each resource', function () {
    login();
    $workspace = Workspace::factory()->create();
    $document = Document::factory()->create(['workspace_id' => $workspace->id]);
    $tag = Tag::factory()->create();

    $this->get('/workspaces')->assertOk();
    $this->get("/workspaces/{$workspace->id}")->assertOk();
    $this->get("/documents/{$document->id}")->assertOk();
    $this->get('/tags')->assertOk();
});
