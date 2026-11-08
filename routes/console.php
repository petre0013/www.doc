<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep unreferenced image uploads daily. Both stacks run this via their
// `scheduler` service (`php artisan schedule:work`); it's also safe to run by
// hand: `php artisan assets:prune` (--dry-run to preview).
Schedule::command('assets:prune')->daily();
Schedule::command('model:prune', ['--model' => [\App\Models\ConversionJob::class]])->daily();

// Audit-trail retention: drop events past the window (365 days by default,
// `audit.retention_days` setting) — the one sanctioned delete path.
Schedule::command('audit:prune')->daily();

// Detect tampering with the audit trail, and prove backups can still be read.
Schedule::command('audit:verify')->daily();
Schedule::command('backup:verify')->weekly();

// Daily digest of due work; incident deadlines are checked every hour.
Schedule::command('reminders:send')->dailyAt('07:00');
Schedule::command('reminders:send', ['--incidents'])->hourly();

// Refresh the latest-release cache for the outdated-version notice. A no-op
// unless an admin opted in (App\Support\UpdateCheck), and silent when offline;
// daily is frequent enough and avoids hammering the GitHub API.
Schedule::command('updates:check')->daily();

// Run a backup if the admin-configured cadence has elapsed. Checked hourly so
// any chosen interval (24h / 48h / weekly) fires close to on time; the command
// itself is the gate, so this stays cheap when backups are disabled or not due.
Schedule::command('backup:run')->hourly();
