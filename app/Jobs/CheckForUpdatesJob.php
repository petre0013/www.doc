<?php

namespace App\Jobs;

use App\Support\UpdateCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the same refresh as the `updates:check` command, off the web request.
 * Dispatched when an admin turns the check ON, so the "Update available" notice
 * can appear immediately instead of waiting for the daily schedule. Never
 * blocks the request; UpdateCheck::refresh() fails silently (no retry storm).
 */
class CheckForUpdatesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        UpdateCheck::refresh();
    }
}
