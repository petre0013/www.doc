<?php

namespace App\Console\Commands;

use App\Support\UpdateCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled daily in routes/console.php (runs in the existing `scheduler`
 * service). Refreshes the cached "latest release" the admin UI compares against
 * — a no-op unless the admin opted in, and silent on any failure. See
 * App\Support\UpdateCheck.
 */
#[Signature('updates:check')]
#[Description('Refresh the latest-release cache when the update check is enabled')]
class CheckForUpdates extends Command
{
    public function handle(): int
    {
        if (! UpdateCheck::isEnabled()) {
            $this->info('Update check is disabled.');

            return self::SUCCESS;
        }

        if (UpdateCheck::isDev()) {
            $this->info('Development build — skipping update check.');

            return self::SUCCESS;
        }

        $tag = UpdateCheck::refresh();

        $this->info($tag ? "Latest release: {$tag}." : 'Update check did not complete (offline?).');

        return self::SUCCESS;
    }
}
