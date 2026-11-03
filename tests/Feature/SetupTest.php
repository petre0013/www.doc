<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Setup;
use Illuminate\Support\Facades\Crypt;

/**
 * Return the instance to a genuinely fresh, unconfigured state: the global
 * beforeEach (tests/Pest.php) marks setup complete so the rest of the suite
 * bypasses the wizard, and RefreshDatabase guarantees no users — so clearing
 * the settings is all it takes to look like a brand-new install.
 */
function freshInstall(): void
{
    Setting::query()->delete();
}

test('a fresh install funnels every request to the setup wizard', function () {
    freshInstall();

    $this->get('/login')->assertRedirect('/setup');
    $this->get('/workspaces')->assertRedirect('/setup');
    $this->get('/setup')->assertOk();
});

test('the wizard is unreachable once the instance is set up', function () {
    login(); // an admin now exists → complete

    $this->get('/setup')->assertRedirect('/');
});

test('the wizard captures the admin then creates and signs them in on finish', function () {
    freshInstall();

    // Step 1 validates and stashes the admin, but does NOT create it yet.
    $this->post('/setup/admin', [
        'name'                  => 'Ada Lovelace',
        'email'                 => 'ada@example.com',
        'password'              => 'supersecret',
        'password_confirmation' => 'supersecret',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    expect(Setup::isComplete())->toBeFalse();

    // Step 2 — instance name.
    $this->post('/setup/instance', ['name' => 'Acme KB'])->assertRedirect();
    expect(Setup::instanceName())->toBe('Acme KB');

    // Finish creates the admin, flips the flag and signs them in.
    $this->post('/setup/complete')->assertRedirect(route('workspaces.index'));

    $admin = User::where('email', 'ada@example.com')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and(Setup::isComplete())->toBeTrue();
    $this->assertAuthenticatedAs($admin);
});

test('finishing setup seeds a Welcome workspace, an intro page and nested guides', function () {
    freshInstall();

    $this->post('/setup/admin', [
        'name'                  => 'Ada Lovelace',
        'email'                 => 'ada@example.com',
        'password'              => 'supersecret',
        'password_confirmation' => 'supersecret',
    ])->assertRedirect();
    $this->post('/setup/complete')->assertRedirect(route('workspaces.index'));

    $workspace = \App\Models\Workspace::where('name', 'Welcome')->first();
    expect($workspace)->not->toBeNull();

    $admin   = User::where('email', 'ada@example.com')->first();
    $welcome = \App\Models\Document::where('workspace_id', $workspace->id)->where('title', 'like', 'Welcome to %')->first();
    expect($welcome)->not->toBeNull()
        ->and($welcome->created_by_id)->toBe($admin->id)        // authored as the new admin
        ->and($welcome->content_html)->toContain('knowledge base')
        ->and($welcome->content_html)->toContain('Core Switch'); // diagram labels reach the rendered HTML

    // The two guides are nested under the welcome page.
    $writing    = \App\Models\Document::where('title', 'Writing & formatting')->first();
    $organising = \App\Models\Document::where('title', 'Organising your knowledge base')->first();
    expect($writing?->parent_id)->toBe($welcome->id)
        ->and($organising?->parent_id)->toBe($welcome->id);

    // The welcome page's wiki-links resolve to the guides (backlinks work).
    $resolved = \App\Models\Link::where('source_document_id', $welcome->id)
        ->pluck('target_document_id')->filter()->values();
    expect($resolved)->toContain($writing->id)
        ->and($resolved)->toContain($organising->id);
});

test('finishing setup without an admin is rejected', function () {
    freshInstall();

    $this->post('/setup/complete')->assertSessionHasErrors('admin');
    expect(Setup::isComplete())->toBeFalse();
    expect(User::count())->toBe(0);
});

test('the admin step requires a unique email and a confirmed password', function () {
    freshInstall();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/setup/admin', [
        'name'                  => 'X',
        'email'                 => 'taken@example.com',
        'password'              => 'short1',
        'password_confirmation' => 'mismatch',
    ])->assertSessionHasErrors(['email', 'password']);
});

test('the wizard saves SMTP settings with the password encrypted at rest', function () {
    freshInstall();

    $this->post('/setup/mail', [
        'host'         => 'smtp.acme.test',
        'port'         => 587,
        'encryption'   => 'tls',
        'username'     => 'mailer',
        'password'     => 's3cret',
        'from_address' => 'docs@acme.test',
        'from_name'    => 'Acme',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $stored = Setting::get('mail');
    expect($stored['host'])->toBe('smtp.acme.test')
        ->and($stored['password'])->not->toBe('s3cret');
    expect(Crypt::decryptString($stored['password']))->toBe('s3cret');
});

test('the wizard test email flashes the staged connection report', function () {
    freshInstall();
    Illuminate\Support\Facades\Mail::fake();
    fakeSmtpProbe();

    $this->post('/setup/mail/test', [
        'host' => 'smtp.acme.test', 'port' => 587, 'encryption' => 'tls',
        'from_address' => 'docs@acme.test', 'to' => 'admin@acme.test',
    ])->assertRedirect()->assertSessionHas('success')
        ->assertSessionHas('smtpTest', fn ($r) => count($r['stages']) === 4 && $r['endpoint'] === 'smtp.acme.test:587');
});

test('setup write actions are forbidden once the instance is set up', function () {
    login(); // complete

    $this->post('/setup/admin', [
        'name'                  => 'Late',
        'email'                 => 'late@example.com',
        'password'              => 'supersecret',
        'password_confirmation' => 'supersecret',
    ])->assertForbidden();
    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

test('the instance name falls back to the config default until chosen', function () {
    freshInstall();
    expect(Setup::instanceName())->toBe(config('app.name'));

    Setting::put('instance', ['name' => 'Acme KB']);
    expect(Setup::instanceName())->toBe('Acme KB');
});
