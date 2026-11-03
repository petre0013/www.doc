<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed access to the `backup` setting blob (schedule + destination + mail).
 * Secrets (SMB / SMTP passwords) are stored ENCRYPTED in the jsonb value and
 * decrypted only here, at point of use. Never expose the raw blob to the UI —
 * use forDisplay(), which strips passwords and replaces them with `*_set` flags.
 */
class BackupSettings
{
    public static function defaults(): array
    {
        $d = config('backup.defaults');

        return [
            'enabled'    => $d['enabled'],
            'interval'   => $d['interval'],
            'retention'  => $d['retention'],
            'driver'     => 'local', // local | smb
            'encryption' => false,   // encrypt archives at rest (needs BACKUP_ENCRYPTION_KEY)
            'smb'  => ['host' => '', 'share' => '', 'path' => '', 'username' => '', 'password' => '', 'domain' => ''],
            'mail' => [
                'enabled'      => false,
                'to'           => '',
                'host'         => '',
                'port'         => 587,
                'username'     => '',
                'password'     => '',
                'encryption'   => 'tls', // tls | ssl | none
                'from_address' => '',
                'from_name'    => config('app.name'),
            ],
        ];
    }

    /** The full settings, merged over defaults (passwords still encrypted). */
    public static function get(): array
    {
        return array_replace_recursive(self::defaults(), Setting::get('backup', []) ?: []);
    }

    public static function smbPassword(): string
    {
        return self::decrypt(self::get()['smb']['password'] ?? '');
    }

    public static function mailPassword(): string
    {
        return self::decrypt(self::get()['mail']['password'] ?? '');
    }

    /** The mail block with its password DECRYPTED, ready to configure a mailer. */
    public static function mailConfig(): array
    {
        $mail = self::get()['mail'];
        $mail['password'] = self::mailPassword();

        return self::applyGlobalMailFallback($mail);
    }

    /**
     * When the backup mail block has no SMTP host of its own, borrow the global
     * mail settings (the setup wizard / admin Email tab) so an operator doesn't
     * re-enter SMTP just to get backup reports. The recipient and on/off toggle
     * always stay with the backup block; only the transport (and a from-address
     * when none is set) falls back.
     *
     * @param array $mail a DECRYPTED backup mail block (password in clear)
     */
    public static function applyGlobalMailFallback(array $mail): array
    {
        if (trim((string) ($mail['host'] ?? '')) !== '' || ! MailSettings::isConfigured()) {
            return $mail;
        }

        $g = MailSettings::get();

        $mail['host']         = $g['host'];
        $mail['port']         = $g['port'];
        $mail['encryption']   = $g['encryption']; // tls|ssl|none (BackupNotifier maps it)
        $mail['username']     = $g['username'];
        $mail['password']     = MailSettings::password();
        $mail['from_address'] = ($mail['from_address'] ?? '') ?: $g['from_address'];
        $mail['from_name']    = ($mail['from_name'] ?? '') ?: $g['from_name'];

        return $mail;
    }

    /** Settings safe to hand the frontend: no passwords, just `*_set` booleans. */
    public static function forDisplay(): array
    {
        $s = self::get();

        $smbSet  = (string) ($s['smb']['password'] ?? '') !== '';
        $mailSet = (string) ($s['mail']['password'] ?? '') !== '';

        unset($s['smb']['password'], $s['mail']['password']);
        $s['smb']['password_set']  = $smbSet;
        $s['mail']['password_set'] = $mailSet;

        // Whether a BACKUP_ENCRYPTION_KEY exists at all — the UI disables the
        // encryption toggle (and shows how to set the key) when it doesn't.
        $s['encryption_available'] = \App\Services\Backup\ArchiveCipher::configured();
        
        // Tells the UI if a key is present but invalid, so it can show an error.
        $s['encryption_key_present'] = (bool) config('backup.encryption_key');

        // Whether a global SMTP server is configured (setup wizard / Email tab).
        // When true, the backup mail fields can be left blank to reuse it.
        $s['global_mail_configured'] = MailSettings::isConfigured();

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
