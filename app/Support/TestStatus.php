<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Persisted result of the last on-demand connection test — Email SMTP, the
 * backup SMB destination, backup mail. Written whenever a "Test connection" /
 * "Send test" action runs, and read by the settings pages to show a passive,
 * at-a-glance status badge WITHOUT re-probing on load (a live probe on every
 * page view would be slow and could hang the request).
 *
 * Stored under its own Setting key (`<key>_test_status`) so writing the mail /
 * backup settings blob never clobbers it. Saving those settings deliberately
 * CLEARS the matching result (see clear()), since a config change makes the
 * previous "verified" stale — the badge then reads "not tested yet" until the
 * operator runs the test again.
 */
class TestStatus
{
    private const SUFFIX = '_test_status';

    /** Record the outcome of a test run for $key (e.g. 'mail', 'backup_destination'). */
    public static function record(string $key, bool $ok, ?string $message = null): void
    {
        Setting::put($key . self::SUFFIX, [
            'ok'      => $ok,
            'at'      => now()->toIso8601String(),
            // A short failure hint for the badge tooltip. Test errors describe the
            // transport (host unreachable, auth rejected…), never credentials.
            'message' => $message !== null ? Str::limit($message, 300) : null,
        ]);
    }

    /** The last recorded outcome for $key ({ ok, at, message }), or null if never tested. */
    public static function get(string $key): ?array
    {
        return Setting::get($key . self::SUFFIX);
    }

    /** Forget the last result — called when a settings change makes it stale. */
    public static function clear(string $key): void
    {
        Setting::put($key . self::SUFFIX, null);
    }
}
