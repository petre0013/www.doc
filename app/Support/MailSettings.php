<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed access to the global `mail` setting blob — the SMTP credentials the
 * whole app sends through (password resets, future notifications). Stored in
 * the `settings` table so a self-hosted operator configures it from the setup
 * wizard / admin UI instead of editing .env and redeploying.
 *
 * The SMTP password is stored ENCRYPTED in the jsonb value and decrypted only
 * here, at point of use. Never hand the raw blob to the frontend — use
 * forDisplay(), which strips the password to a `password_set` flag.
 *
 * Mirrors App\Support\BackupSettings (the backups feature's independent mailer);
 * the difference is scope — this one drives Laravel's DEFAULT mailer, applied in
 * AppServiceProvider::boot(). See [[project_backups_nis2]] for the sibling.
 */
class MailSettings
{
    public static function defaults(): array
    {
        return [
            'host'         => '',
            'port'         => 587,
            'encryption'   => 'tls', // tls | ssl | none
            'verify_peer'  => true,  // TLS certificate verification (off = self-signed/internal-CA relays)
            'username'     => '',
            'password'     => '',    // stored encrypted
            'from_address' => '',
            'from_name'    => config('app.name'),
        ];
    }

    /** The full settings, merged over defaults (password still encrypted). */
    public static function get(): array
    {
        return array_replace(self::defaults(), Setting::get('mail', []) ?: []);
    }

    /** Persist a raw settings array verbatim (password already encrypted). */
    public static function put(array $settings): void
    {
        Setting::put('mail', $settings);
    }

    /**
     * Save validated form input. A blank password PRESERVES the stored cipher
     * (so the admin needn't re-enter it on every edit); a non-blank one is
     * encrypted before storage. Mirrors the backups settings convention.
     */
    public static function save(array $input): void
    {
        $current  = self::get();
        $password = ($input['password'] ?? '') !== ''
            ? self::encrypt($input['password'])
            : ($current['password'] ?? '');

        self::put([
            'host'         => $input['host'] ?? '',
            'port'         => (int) ($input['port'] ?? 587),
            'encryption'   => $input['encryption'] ?? 'tls',
            'verify_peer'  => (bool) ($input['verify_peer'] ?? true),
            'username'     => $input['username'] ?? '',
            'password'     => $password,
            'from_address' => $input['from_address'] ?? '',
            'from_name'    => ($input['from_name'] ?? '') ?: config('app.name'),
        ]);
    }

    /**
     * A decrypted config for a TEST send of possibly-unsaved form input: a blank
     * password falls back to the stored one (the admin is testing without
     * re-typing it).
     */
    public static function testConfig(array $input): array
    {
        $current = self::get();

        return [
            'host'         => $input['host'] ?? $current['host'],
            'port'         => (int) ($input['port'] ?? $current['port']),
            'encryption'   => $input['encryption'] ?? $current['encryption'],
            'verify_peer'  => (bool) ($input['verify_peer'] ?? $current['verify_peer']),
            'username'     => $input['username'] ?? $current['username'],
            'password'     => ($input['password'] ?? '') !== '' ? $input['password'] : self::password(),
            'from_address' => $input['from_address'] ?? $current['from_address'],
            'from_name'    => ($input['from_name'] ?? '') ?: config('app.name'),
        ];
    }

    /** True once a host is set — i.e. mail can actually be delivered. */
    public static function isConfigured(): bool
    {
        return (string) (self::get()['host'] ?? '') !== '';
    }

    public static function password(): string
    {
        return self::decrypt(self::get()['password'] ?? '');
    }

    /**
     * A Laravel `mail` config fragment built from the stored settings, password
     * decrypted — ready to splice over config('mail.*'). 'none' encryption maps
     * to a null transport encryption (Symfony's expectation).
     */
    public static function config(): array
    {
        $mail       = self::get();
        $encryption = ($mail['encryption'] ?? 'tls') === 'none' ? null : ($mail['encryption'] ?? 'tls');

        return [
            'mailer' => [
                'transport'  => 'smtp',
                'host'       => $mail['host'] ?? '',
                'port'       => (int) ($mail['port'] ?? 587),
                'encryption' => $encryption,
                // Symfony's EsmtpTransportFactory reads this from the DSN
                // options and drops peer + peer-name verification when false.
                'verify_peer' => (bool) ($mail['verify_peer'] ?? true),
                'username'   => ($mail['username'] ?? '') ?: null,
                'password'   => self::password() ?: null,
                'timeout'    => 15,
            ],
            'from' => [
                'address' => ($mail['from_address'] ?? '') ?: config('mail.from.address'),
                'name'    => ($mail['from_name'] ?? '') ?: config('app.name'),
            ],
        ];
    }

    /** Push the stored SMTP settings onto the default mailer for this request. */
    public static function applyToMailer(): void
    {
        if (! self::isConfigured()) {
            return;
        }

        $c = self::config();

        config([
            'mail.default'         => 'smtp',
            'mail.mailers.smtp'    => $c['mailer'],
            'mail.from.address'    => $c['from']['address'],
            'mail.from.name'       => $c['from']['name'],
        ]);
    }

    /** Settings safe to hand the frontend: no password, just a `password_set` flag. */
    public static function forDisplay(): array
    {
        $s = self::get();

        $s['password_set'] = (string) ($s['password'] ?? '') !== '';
        unset($s['password']);

        return $s;
    }

    public static function encrypt(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    private static function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable) {
            return '';
        }
    }
}
