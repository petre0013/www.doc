<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CheckForUpdatesJob;
use App\Support\Audit;
use App\Support\Setup;
use App\Support\UpdateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Updates" settings tab: the running version, the opt-in release check and
 * its status, cached notes for the latest release, and a little system info.
 * The version caption in the Settings footer links here.
 */
class UpdateSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Updates', [
            'status'       => UpdateCheck::status(),
            'notesHtml'    => $this->renderNotes(UpdateCheck::get()['latest_notes'] ?? null),
            'releasesUrl'  => 'https://github.com/' . config('updates.repo') . '/releases',
            'system'       => [
                'app_version' => UpdateCheck::currentVersion(),
                'php'         => PHP_VERSION,
                'laravel'     => app()->version(),
                'instance'    => Setup::instanceName(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->validate([
            'enabled' => ['required', 'boolean'],
        ])['enabled'];

        $wasEnabled = UpdateCheck::isEnabled();
        UpdateCheck::setEnabled($enabled);

        Audit::record('settings.updates_updated', null, ['enabled' => $enabled]);

        // Turning it on: refresh immediately (off the request) so the notice can
        // appear now rather than after the daily schedule. No-op on a dev build.
        if ($enabled && ! $wasEnabled && ! UpdateCheck::isDev()) {
            CheckForUpdatesJob::dispatch();
        }

        return back()->with('success', $enabled ? 'Update checks enabled.' : 'Update checks disabled.');
    }

    /**
     * Manual "Check now" — refreshes off the request; the page polls checked_at
     * and shows progress/result inline (no ack toast — the button's own
     * "Checking…" state already covers it; a toast here would repeat on every
     * poll's Inertia visit).
     */
    public function check(): RedirectResponse
    {
        if (! UpdateCheck::isEnabled()) {
            return back()->with('error', 'Enable update checks first.');
        }

        if (UpdateCheck::isDev()) {
            return back()->with('error', 'Update checks run only on tagged releases.');
        }

        CheckForUpdatesJob::dispatch();

        return back();
    }

    /**
     * Render a release's markdown notes to sanitized HTML. `html_input => escape`
     * neutralizes any raw HTML in the remote body and unsafe (javascript:) links
     * are dropped — the notes come from an external source and are admin-visible.
     */
    private function renderNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return null;
        }

        return (string) Str::markdown($notes, [
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
