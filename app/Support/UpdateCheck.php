<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The outdated-version notification (CLAUDE.md "Operations & Maintenance").
 *
 * An OPT-IN check that reads the newest published GitHub release and, when this
 * instance's app.version is older, lets the admin UI show a muted "Update
 * available" notice next to the Settings version caption. Mirrors the typed
 * settings accessors ([[MailSettings]] / [[BackupSettings]]) — one `updates`
 * blob in the `settings` table.
 *
 * Self-hosted reality (see config/updates.php): the check reaches an external
 * host, so it is OFF until an admin turns it on, sends NO telemetry (a plain
 * read of public metadata), and fails SILENTLY offline/air-gapped — refresh()
 * never throws, and the daily schedule means no repeated hammering.
 */
class UpdateCheck
{
    public static function defaults(): array
    {
        return [
            'enabled'        => (bool) config('updates.default_enabled', false),
            // All the below are machine-written by refresh() from the newest release.
            'latest_version' => null,   // the release tag ("v1.4.0")
            'latest_name'    => null,   // human title (falls back to the tag)
            'latest_notes'   => null,   // release body, raw markdown (rendered at display time)
            'latest_url'     => null,   // html_url of the release on GitHub
            'published_at'   => null,   // ISO8601 publish time of the release
            'checked_at'     => null,   // ISO8601 of the last SUCCESSFUL check
        ];
    }

    /** The full settings blob, merged over defaults. */
    public static function get(): array
    {
        return array_replace(self::defaults(), Setting::get('updates', []) ?: []);
    }

    public static function isEnabled(): bool
    {
        return (bool) self::get()['enabled'];
    }

    /** Flip the opt-in toggle; the cached result is left untouched. */
    public static function setEnabled(bool $enabled): void
    {
        Setting::put('updates', array_replace(self::get(), ['enabled' => $enabled]));
    }

    /** The running release ("dev" for source builds — never checked). */
    public static function currentVersion(): string
    {
        return (string) (config('app.version') ?: 'dev');
    }

    public static function isDev(): bool
    {
        return self::currentVersion() === 'dev';
    }

    /**
     * True when the check is on, this is a real (non-dev) build, and a newer
     * release than the running one has been recorded.
     */
    public static function updateAvailable(): bool
    {
        $s = self::get();

        if (! $s['enabled'] || self::isDev() || ! $s['latest_version']) {
            return false;
        }

        return version_compare(
            self::normalize($s['latest_version']),
            self::normalize(self::currentVersion()),
            '>'
        );
    }

    /**
     * Fetch the newest release tag and cache it. Returns the tag on success, or
     * null when skipped (disabled/dev) or on ANY failure — it NEVER throws, so
     * an offline/air-gapped instance degrades to "no notice", never an error.
     */
    public static function refresh(): ?string
    {
        if (! self::isEnabled() || self::isDev()) {
            return null;
        }

        try {
            $url = 'https://api.github.com/repos/' . config('updates.repo') . '/releases/latest';

            // Rule 6: every external fetch validates through the SSRF guard,
            // even for a fixed public host, and pins to the resolved IPs.
            $ips = Ssrf::assertPublicUrl($url);

            $response = Http::withOptions(Ssrf::fetchOptions($url, $ips))
                ->timeout((int) config('updates.timeout', 8))
                ->withHeaders([
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => config('app.name', 'www.doc') . ' update-check',
                ])
                ->get($url);

            $data = $response->successful() ? (array) $response->json() : [];
            $tag  = trim((string) ($data['tag_name'] ?? ''));

            if ($tag === '') {
                return null;
            }

            Setting::put('updates', array_replace(self::get(), [
                'latest_version' => $tag,
                'latest_name'    => trim((string) ($data['name'] ?? '')) ?: $tag,
                'latest_notes'   => (string) ($data['body'] ?? ''),
                'latest_url'     => (string) ($data['html_url'] ?? ''),
                'published_at'   => (string) ($data['published_at'] ?? ''),
                'checked_at'     => now()->toIso8601String(),
            ]));

            return $tag;
        } catch (\Throwable $e) {
            // Fail silently — no user-facing error, no blocking. Note it in the
            // log for operators who go looking; the daily cadence prevents any
            // retry storm on a persistently unreachable network.
            Log::info('Update check failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Full state for the Updates settings tab. No secrets. */
    public static function status(): array
    {
        $s = self::get();

        return [
            'enabled'          => (bool) $s['enabled'],
            'current'          => self::currentVersion(),
            'latest'           => $s['latest_version'],
            'latest_name'      => $s['latest_name'],
            'latest_url'       => $s['latest_url'],
            'published_at'     => $s['published_at'],
            'checked_at'       => $s['checked_at'],
            'is_dev'           => self::isDev(),
            'update_available' => self::updateAvailable(),
        ];
    }

    /** Strip a leading "v" so version_compare sees a clean semver string. */
    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
