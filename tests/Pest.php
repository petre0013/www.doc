<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Swap the staged SMTP prober for a canned pass-through in feature tests that
 * hit a "Send test email" endpoint — no live DNS/socket probing in the suite.
 * The fake still invokes the real send callable (so Mail::fake assertions
 * work) and reports its outcome as the send stage. Pass explicit $stages to
 * simulate a specific probe result instead (the send callable then never runs,
 * mirroring a probe that failed before the send stage).
 */
function fakeSmtpProbe(?array $stages = null): void
{
    app()->bind(\App\Support\Smtp\SmtpProbe::class, fn () => new class($stages) extends \App\Support\Smtp\SmtpProbe
    {
        public function __construct(private ?array $canned)
        {
            parent::__construct();
        }

        public function run(string $host, int $port, string $encryption, ?callable $send = null, bool $verifyPeer = true): array
        {
            if ($this->canned !== null) {
                return $this->canned;
            }

            $sendStage = ['stage' => 'send', 'status' => 'ok', 'detail' => 'sent'];
            if ($send !== null) {
                try {
                    $send();
                } catch (\Throwable $e) {
                    $sendStage = ['stage' => 'send', 'status' => 'failed', 'detail' => $e->getMessage()];
                }
            }

            return [
                ['stage' => 'dns', 'status' => 'ok', 'detail' => 'resolved'],
                ['stage' => 'connect', 'status' => 'ok', 'detail' => 'connected'],
                ['stage' => 'tls', 'status' => 'ok', 'detail' => 'secured'],
                $sendStage,
            ];
        }
    });
}

/**
 * Create and authenticate a user, returning it. Mirrors the v1 "everyone-admin"
 * default so policy-gated routes are reachable; pass $role to test a narrower
 * role. Roles are created on demand since RefreshDatabase doesn't seed them.
 */
function login(?\App\Models\User $user = null, string $role = 'admin'): \App\Models\User
{
    foreach (['admin', 'editor', 'viewer'] as $name) {
        \Spatie\Permission\Models\Role::findOrCreate($name, 'web');
    }

    $user ??= \App\Models\User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

/**
 * Every persisted node/mark in the schema, in a single document. THE canonical
 * schema fixture: SchemaParityTest asserts each piece survives the JSON→HTML
 * render, and DocumentDiffTest asserts the differ understands every node — so
 * a new editor node added here (mandatory per the add-editor-node skill) fails
 * loudly in whichever half was forgotten.
 */
function fixtureDoc(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [
            ['type' => 'text', 'text' => 'Heading Centered'],
        ]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'bold '],
            ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'italic '],
            ['type' => 'text', 'marks' => [['type' => 'strike']], 'text' => 'strike '],
            ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'code '],
            ['type' => 'text', 'marks' => [['type' => 'underline']], 'text' => 'under '],
            ['type' => 'text', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#ff0000']]], 'text' => 'red '],
            ['type' => 'text', 'marks' => [['type' => 'highlight', 'attrs' => ['color' => '#ffff00']]], 'text' => 'hl '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]], 'text' => 'link'],
        ]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'bullet']]],
        ]]]],
        ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'numbered']]],
        ]]]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'quoted'],
        ]]]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 2;']]],
        ['type' => 'taskList', 'content' => [
            ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'done task']]],
            ]],
            ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'open task']]],
            ]],
        ]],
        ['type' => 'callout', 'attrs' => ['kind' => 'warning'], 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'callout body']]],
        ]],
        ['type' => 'horizontalRule'],
        ['type' => 'image', 'attrs' => ['src' => '/storage/assets/x.png', 'alt' => 'pic', 'width' => 200, 'align' => 'center']],
        ['type' => 'wikiLink', 'attrs' => ['title' => 'Other Page', 'target_id' => 7]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableHeader', 'attrs' => ['backgroundColor' => '#DAE6D4', 'colwidth' => [100]], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'H1']]]]],
            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cell']]]]],
        ]]]],
        ['type' => 'networkDiagram', 'attrs' => ['name' => 'My Net', 'graph' => [
            'nodes' => [['id' => 'n1', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'router']]],
            'edges' => [],
        ]]],
    ]];
}
