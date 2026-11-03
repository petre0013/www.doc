<?php

use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use Database\Factories\DocumentFactory;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot reach search', function () {
    $this->get('/search?q=anything')->assertRedirect('/login');
});

test('an empty query renders the page with no results', function () {
    login();
    Document::factory()->create(['title' => 'Some Page']);

    $this->get('/search?q=')->assertOk()->assertInertia(
        fn (Assert $page) => $page->component('Search/Index')->where('q', '')->has('results', 0)
    );
});

test('a document is found by its title', function () {
    login();
    Document::factory()->create(['title' => 'Kubernetes Onboarding Guide']);
    Document::factory()->create(['title' => 'Unrelated Payroll Notes']);

    $this->get('/search?q=Kubernetes')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.type', 'document')
            ->where('results.0.title', 'Kubernetes Onboarding Guide')
    );
});

test('a document is found by its body text', function () {
    login();
    Document::factory()->create([
        'title'   => 'Release Notes',
        'content' => DocumentFactory::tiptap('The quarterly Helmsman migration is scheduled for Friday.'),
    ]);

    $this->get('/search?q=Helmsman')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Release Notes')
    );
});

test('a title match outranks a body-only match for the same term', function () {
    login();
    $bodyMatch = Document::factory()->create([
        'title'   => 'Weekly Sync Notes',
        'content' => DocumentFactory::tiptap('We discussed the Borealis rollout at length.'),
    ]);
    $titleMatch = Document::factory()->create([
        'title'   => 'Borealis Architecture',
        'content' => DocumentFactory::tiptap('Background reading for the team.'),
    ]);

    // Title weight (A) must rank above body weight (B): the title match comes first.
    $this->get('/search?q=Borealis')->assertInertia(
        fn (Assert $page) => $page
            ->has('results', 2)
            ->where('results.0.id', $titleMatch->id)
            ->where('results.1.id', $bodyMatch->id)
    );
});

test('documents in a trashed workspace are excluded from search', function () {
    login();
    $workspace = Workspace::factory()->create();
    Document::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Telescope Spec']);

    $this->get('/search?q=Telescope')->assertInertia(fn (Assert $page) => $page->has('results', 1));

    $workspace->trashWithDocuments();

    $this->get('/search?q=Telescope')->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('a workspace is found by name and by description', function () {
    login();
    Workspace::factory()->create(['name' => 'Engineering Handbook', 'description' => 'Internal runbooks']);

    $this->get('/search?q=Handbook')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.type', 'workspace')
    );

    $this->get('/search?q=runbooks')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.type', 'workspace')
    );
});

test('a tag is found by name', function () {
    login();
    Tag::factory()->create(['name' => 'security-review']);

    $this->get('/search?q=security-review')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.type', 'tag')
    );
});

test('a document result carries its tags', function () {
    login();
    $tag = Tag::factory()->create(['name' => 'runbook']);
    $document = Document::factory()->create(['title' => 'Incident Pelican Playbook']);
    $document->tags()->attach($tag->id);

    $this->get('/search?q=Pelican')->assertInertia(
        fn (Assert $page) => $page
            ->has('results.0.tags', 1)
            ->where('results.0.tags.0.name', 'runbook')
    );
});

test('a body match returns an excerpt with HTML stripped for frontend highlighting', function () {
    login();
    Document::factory()->create([
        'title'   => 'Networking',
        'content' => DocumentFactory::tiptap('Rotate the Cormorant credentials every ninety days without exception.'),
    ]);

    $this->get('/search?q=Cormorant')->assertInertia(function (Assert $page) {
        $excerpt = $page->toArray()['props']['results'][0]['excerpt'];

        expect($excerpt)->not->toContain('<mark>')
            ->and(strtolower($excerpt))->toContain('cormorant')
            ->and($excerpt)->not->toContain('<p>');
    });
});

test('LIKE wildcards in the query are treated literally, not as wildcards', function () {
    login();
    Document::factory()->create(['title' => 'Alpha Notes']);
    Document::factory()->create(['title' => 'Beta Notes']);

    // A bare "%" must not behave as a match-everything LIKE pattern. With proper
    // escaping it matches only a literal "%", which nothing here contains.
    $this->get('/search?q=%25')->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('symbol characters in a query do not crash to_tsquery', function () {
    login();
    Document::factory()->create([
        'title'   => 'Generics',
        'content' => DocumentFactory::tiptap('Prefer List over raw arrays when order matters.'),
    ]);

    // `<`/`>` sit in the Unicode Symbol category, so a whitespace/punctuation-only
    // split let them reach to_tsquery as operators — "List<T>" raised
    // "syntax error in tsquery" (a 500). These must return cleanly (200) now, and
    // the alphanumeric lexemes ("list") should still match the page.
    foreach (['List<T>', 'a<b', '5<10', 'Map<K,V>', 'x -> y'] as $query) {
        $this->get('/search?q=' . urlencode($query))->assertOk();
    }

    $this->get('/search?q=' . urlencode('List<T>'))->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Generics')
    );
});

test('operator-like characters split terms rather than acting as tsquery operators', function () {
    login();
    Document::factory()->create([
        'title'   => 'Boolean',
        'content' => DocumentFactory::tiptap('The Falcon flag toggles the feature.'),
    ]);

    // "|" is a tsquery OR operator; letting it through meant a stray symbol changed
    // the query's meaning. It's now just a separator between two prefix lexemes
    // ("falcon" AND "flag"), both present in the page, so this matches cleanly.
    $this->get('/search?q=' . urlencode('Falcon|flag'))->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Boolean')
    );
});

test('a document is found by an IP address in its body', function () {
    login();
    Document::factory()->create([
        'title'   => 'Rack Inventory',
        'content' => DocumentFactory::tiptap('The primary host lives at 192.168.5.5 in rack 4.'),
    ]);

    $this->get('/search?q=192.168.5.5')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Rack Inventory')
    );
});

test('a document is found by a dotted version number', function () {
    login();
    Document::factory()->create([
        'title'   => 'Upgrade Log',
        'content' => DocumentFactory::tiptap('Bumped the gateway to v1.2.3 last night.'),
    ]);

    $this->get('/search?q=v1.2.3')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Upgrade Log')
    );
});

test('prefix search still matches a partial word', function () {
    login();
    Document::factory()->create([
        'title'   => 'Server Runbook',
        'content' => DocumentFactory::tiptap('Restart the Server1 process carefully.'),
    ]);

    // "serv" must still prefix-match "Server1"/"Server" (regression guard).
    $this->get('/search?q=serv')->assertInertia(
        fn (Assert $page) => $page->has('results', 1)->where('results.0.title', 'Server Runbook')
    );
});

test('a query with tsquery operator characters does not error', function () {
    login();
    Document::factory()->create([
        'title'   => 'Generics Notes',
        'content' => DocumentFactory::tiptap('Using List<T> across the codebase.'),
    ]);

    // Angle brackets must never reach to_tsquery as operators (would 500).
    $this->get('/search?q=' . urlencode('List<T>'))->assertOk();
    $this->get('/search?q=' . urlencode('a<b'))->assertOk();
});
