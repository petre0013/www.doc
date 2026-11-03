<?php

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

it('creates roles, an admin, and a welcome page on a fresh instance', function () {
    $this->artisan('app:install', [
        '--email' => 'boss@example.test',
        '--password' => 'supersecret',
        '--name' => 'Boss',
    ])->assertSuccessful();

    $admin = User::where('email', 'boss@example.test')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue();

    $welcome = Document::where('title', 'Welcome to www.doc')->first();
    expect(Workspace::where('name', 'Welcome')->exists())->toBeTrue()
        ->and($welcome)->not->toBeNull()
        // Rendered through RenderDocument (observer) and attributed to the admin.
        ->and($welcome->content_html)->toContain('Welcome to www.doc')
        ->and($welcome->created_by_id)->toBe($admin->id);
});

it('is idempotent: promotes an existing user and does not duplicate the welcome', function () {
    User::factory()->create(['email' => 'me@example.test']);

    $this->artisan('app:install', ['--email' => 'me@example.test', '--password' => 'supersecret', '--name' => 'Me'])
        ->assertSuccessful();
    // Re-run against the now-existing account.
    $this->artisan('app:install', ['--email' => 'me@example.test'])->assertSuccessful();

    expect(User::where('email', 'me@example.test')->count())->toBe(1)
        ->and(User::where('email', 'me@example.test')->first()->hasRole('admin'))->toBeTrue()
        ->and(Workspace::where('name', 'Welcome')->count())->toBe(1);
});

it('skips the welcome content with --no-welcome', function () {
    $this->artisan('app:install', [
        '--email' => 'solo@example.test',
        '--password' => 'supersecret',
        '--no-welcome' => true,
    ])->assertSuccessful();

    expect(Workspace::count())->toBe(0);
});

it('fails when no admin email is provided', function () {
    // Interactively answering the email prompt with nothing aborts cleanly;
    // under a non-interactive `docker exec` the prompt simply returns null too.
    $this->artisan('app:install', ['--no-welcome' => true])
        ->expectsQuestion('Admin email', '')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});
