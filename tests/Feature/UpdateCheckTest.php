<?php

use App\Jobs\CheckForUpdatesJob;
use App\Models\AuditEvent;
use App\Support\UpdateCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // A real (non-dev) build so the check is active; the repo is a fixed public host.
    config(['app.version' => '1.3.0', 'updates.repo' => 'acme/docs']);
});

/** Fake the GitHub releases endpoint with a given tag (and optional notes). */
function fakeRelease(string $tag, array $extra = []): void
{
    Http::fake([
        'api.github.com/*' => Http::response(array_replace(['tag_name' => $tag], $extra), 200),
    ]);
}

test('the check is disabled by default and makes no request', function () {
    Http::fake();

    expect(UpdateCheck::isEnabled())->toBeFalse();
    UpdateCheck::refresh();

    Http::assertNothingSent();
    expect(UpdateCheck::get()['latest_version'])->toBeNull();
});

test('an enabled check records the latest release and flags an update', function () {
    fakeRelease('v1.4.0');
    UpdateCheck::setEnabled(true);

    expect(UpdateCheck::refresh())->toBe('v1.4.0');

    $status = UpdateCheck::status();
    expect($status['latest'])->toBe('v1.4.0')
        ->and($status['checked_at'])->not->toBeNull()
        ->and($status['update_available'])->toBeTrue();
});

test('no update is flagged when the running build is current', function () {
    fakeRelease('v1.3.0');
    UpdateCheck::setEnabled(true);
    UpdateCheck::refresh();

    expect(UpdateCheck::updateAvailable())->toBeFalse();
});

test('the semver compare ignores a leading v and does not misread patch order', function () {
    UpdateCheck::setEnabled(true);
    config(['app.version' => '1.10.0']);
    fakeRelease('1.9.0'); // 1.9.0 < 1.10.0 — must NOT be seen as newer
    UpdateCheck::refresh();

    expect(UpdateCheck::updateAvailable())->toBeFalse();
});

test('dev builds never check, even when enabled', function () {
    Http::fake();
    config(['app.version' => 'dev']);
    UpdateCheck::setEnabled(true);

    expect(UpdateCheck::refresh())->toBeNull();
    Http::assertNothingSent();
    expect(UpdateCheck::status()['is_dev'])->toBeTrue()
        ->and(UpdateCheck::updateAvailable())->toBeFalse();
});

test('a failed fetch is swallowed — no exception, no cached result', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('offline'));
    UpdateCheck::setEnabled(true);

    expect(UpdateCheck::refresh())->toBeNull()
        ->and(UpdateCheck::get()['latest_version'])->toBeNull();
});

test('the scheduled command refreshes when enabled', function () {
    fakeRelease('v2.0.0');
    UpdateCheck::setEnabled(true);

    $this->artisan('updates:check')->assertSuccessful();

    expect(UpdateCheck::get()['latest_version'])->toBe('v2.0.0');
});

test('non-admins cannot toggle the update check', function () {
    login(null, 'editor');

    $this->patch('/admin/settings/updates', ['enabled' => true])->assertForbidden();
});

test('an admin enabling the check persists it, audits it, and dispatches a refresh', function () {
    Queue::fake();
    login();

    $this->patch('/admin/settings/updates', ['enabled' => true])
        ->assertRedirect()->assertSessionHas('success');

    expect(UpdateCheck::isEnabled())->toBeTrue();

    $event = AuditEvent::firstWhere('event', 'settings.updates_updated');
    expect($event)->not->toBeNull()
        ->and($event->context['enabled'])->toBeTrue();

    Queue::assertPushed(CheckForUpdatesJob::class);
});

test('disabling the check does not dispatch a refresh', function () {
    Queue::fake();
    login();
    UpdateCheck::setEnabled(true);

    $this->patch('/admin/settings/updates', ['enabled' => false])
        ->assertRedirect();

    expect(UpdateCheck::isEnabled())->toBeFalse();
    Queue::assertNothingPushed();
});

test('refresh caches the release name, notes and url', function () {
    fakeRelease('v1.4.0', [
        'name'     => 'v1.4.0 — Updates',
        'body'     => "## Highlights\n- Update notifications",
        'html_url' => 'https://github.com/acme/docs/releases/tag/v1.4.0',
    ]);
    UpdateCheck::setEnabled(true);
    UpdateCheck::refresh();

    $s = UpdateCheck::get();
    expect($s['latest_name'])->toBe('v1.4.0 — Updates')
        ->and($s['latest_notes'])->toContain('Update notifications')
        ->and($s['latest_url'])->toBe('https://github.com/acme/docs/releases/tag/v1.4.0');
});

test('the Updates tab renders with status, system info and rendered notes', function () {
    login();
    UpdateCheck::setEnabled(true);
    fakeRelease('v1.4.0', ['name' => 'v1.4.0', 'body' => '## Highlights']);
    UpdateCheck::refresh();

    $this->get('/admin/settings/updates')->assertOk()->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Settings/Updates')
            ->where('status.latest', 'v1.4.0')
            ->has('system.php')
            ->has('system.laravel')
            ->where('notesHtml', fn ($html) => str_contains((string) $html, '<h2>Highlights</h2>')),
    );
});

test('rendered notes escape embedded HTML (no injection from remote content)', function () {
    login();
    UpdateCheck::setEnabled(true);
    fakeRelease('v1.4.0', ['body' => 'Hello <script>alert(1)</script>']);
    UpdateCheck::refresh();

    $this->get('/admin/settings/updates')->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('notesHtml', fn ($html) => ! str_contains((string) $html, '<script>')
                && str_contains((string) $html, '&lt;script&gt;')),
    );
});

test('non-admins cannot open the Updates tab', function () {
    login(null, 'editor');
    $this->get('/admin/settings/updates')->assertForbidden();
});

test('Check now dispatches a refresh when enabled, and is gated otherwise', function () {
    Queue::fake();
    login();

    // Disabled → refused, no job.
    $this->post('/admin/settings/updates/check')->assertRedirect()->assertSessionHas('error');
    Queue::assertNothingPushed();

    // Enabled → dispatches. No ack flash — the page shows progress inline
    // (a toast here would repeat on every poll's Inertia visit).
    UpdateCheck::setEnabled(true);
    $this->post('/admin/settings/updates/check')->assertRedirect()->assertSessionMissing('success');
    Queue::assertPushed(CheckForUpdatesJob::class);
});

test('admins receive the update status as a shared prop; non-admins do not', function () {
    login();
    $this->get('/settings/profile')->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page->has('updateStatus'),
    );

    login(null, 'editor');
    $this->get('/settings/profile')->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page->where('updateStatus', null),
    );
});
