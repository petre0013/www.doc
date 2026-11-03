<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outdated-version notification
    |--------------------------------------------------------------------------
    |
    | An OPT-IN check that compares this instance's app.version against the
    | newest published GitHub release and, when older, shows admins a muted
    | "Update available" notice in Settings. It performs a single, read-only
    | GET of public release metadata — it sends NO telemetry about this
    | instance. Off by default: a self-hosted, possibly air-gapped instance
    | should stay self-contained until an admin knowingly turns the check on.
    |
    | See App\Support\UpdateCheck and the `updates:check` command.
    |
    */

    // owner/name of the repository whose releases are checked.
    'repo' => env('UPDATE_CHECK_REPO', 'petre0013/www.doc'),

    // Default for a fresh instance / the setup wizard. Off per the self-hosted
    // reality above; an admin opts in from the wizard or Settings.
    'default_enabled' => (bool) env('UPDATE_CHECK_DEFAULT', false),

    // Seconds to wait on the GitHub API before giving up (fail silently).
    'timeout' => (int) env('UPDATE_CHECK_TIMEOUT', 8),

];
