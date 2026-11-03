<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * First-run state for the installation wizard. The app is "set up" once an
 * operator has created the first account and finished the wizard.
 *
 * Completion is the `setup` flag OR the presence of any user — so an EXISTING
 * install is never trapped behind the wizard, and an upgrade that predates this
 * feature just works. Only a truly fresh install (no flag, no users) sees it.
 * (A plain user-existence check, not a role query, keeps this free of the
 * spatie permission tables — it runs on every request via EnsureSetupComplete.)
 */
class Setup
{
    public static function isComplete(): bool
    {
        if (Setting::get('setup', [])['completed_at'] ?? false) {
            return true;
        }

        return User::exists();
    }

    public static function markComplete(): void
    {
        Setting::put('setup', ['completed_at' => now()->toIso8601String()]);
    }

    /** The operator-chosen instance name, falling back to the configured default. */
    public static function instanceName(): string
    {
        return Setting::get('instance', [])['name'] ?? config('app.name');
    }
}
