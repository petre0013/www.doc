<?php

namespace App\Support;

use App\Mail\TestMail;
use App\Support\Smtp\ErrorClassifier;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a one-off test message through SMTP settings the operator is editing —
 * which may be UNSAVED — to confirm they work before relying on them. Builds a
 * transient `smtp-test` mailer at runtime (the global mailer is reserved for the
 * persisted settings); transport failures surface as classified diagnoses
 * (Smtp\ErrorClassifier).
 *
 * Sibling to the backups feature's BackupNotifier::sendTest; this one is for the
 * global mailer (password resets, app notifications).
 */
class MailTester
{
    /**
     * @param array  $mail decrypted mail config (host/port/encryption/username/password/from_*)
     * @param string $to   recipient address
     *
     * @throws \RuntimeException with an admin-readable message on transport failure
     */
    public function send(array $mail, string $to): void
    {
        $encryption = ($mail['encryption'] ?? 'tls') === 'none' ? null : ($mail['encryption'] ?? 'tls');

        config(['mail.mailers.smtp-test' => [
            'transport'  => 'smtp',
            'host'       => $mail['host'] ?? '',
            'port'       => (int) ($mail['port'] ?? 587),
            'encryption' => $encryption,
            'verify_peer' => (bool) ($mail['verify_peer'] ?? true),
            'username'   => ($mail['username'] ?? '') ?: null,
            'password'   => ($mail['password'] ?? '') ?: null,
            'timeout'    => 15,
        ]]);

        $fromAddress = ($mail['from_address'] ?? '') ?: (config('mail.from.address') ?: $to);
        $fromName    = ($mail['from_name'] ?? '') ?: config('app.name');

        try {
            Mail::mailer('smtp-test')->to($to)->send(new TestMail($fromAddress, $fromName));
        } catch (\Throwable $e) {
            throw new \RuntimeException(ErrorClassifier::message($e, $mail), previous: $e);
        }
    }
}
