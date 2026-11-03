<?php

namespace App\Support\Smtp;

use Illuminate\Support\Str;

/**
 * THE mapping from raw SMTP transport failures to admin-readable diagnoses —
 * replaces the near-duplicate friendly() maps that MailTester and
 * BackupNotifier used to carry. Two rules the old maps broke:
 *
 *  1. Distinct failures stay distinct: "refused" (wrong port / service down),
 *     "timed out" (firewall / VLAN routing) and DNS/TLS/auth problems point at
 *     different fixes and must never collapse into one hint.
 *  2. The message says WHAT WAS ATTEMPTED (host:port, encryption mode) and
 *     keeps the raw transport text, so the admin can diagnose from the UI or
 *     the backup banner without grepping laravel.log.
 *
 * Classification only — never probes or reconnects, so it is safe in queue
 * jobs and on every real-send failure path (backup report_error, password
 * resets). Interactive probing is SmtpProbe's job.
 */
class ErrorClassifier
{
    /**
     * Categorize a transport failure against the mail config that produced it
     * (`host`/`port`/`encryption` are read; missing keys degrade gracefully).
     *
     * @return array{category: string, message: string}  category is one of
     *         dns|refused|timeout|auth|tls|smtp|unknown
     */
    public static function classify(\Throwable $e, array $mail): array
    {
        $raw = trim($e->getMessage());
        $host = (string) ($mail['host'] ?? '?');
        $port = (int) ($mail['port'] ?? 0);
        $encryption = (string) ($mail['encryption'] ?? 'tls');
        $endpoint = "{$host}:{$port}";

        $m = strtolower($raw);

        // Order matters: specific transport signals before the generic
        // reply-code match (an auth failure also carries a 535 code).
        $category = match (true) {
            str_contains($m, 'getaddrinfo')
            || str_contains($m, 'name or service not known')
            || str_contains($m, 'name resolution')
            || str_contains($m, 'temporary failure in name')
                => 'dns',

            str_contains($m, 'connection refused') => 'refused',

            str_contains($m, 'timed out') || str_contains($m, 'timeout') => 'timeout',

            str_contains($m, 'authenticat')
            || str_contains($m, 'credentials')
            || preg_match('/\b53[45]\b/', $m) === 1
                => 'auth',

            str_contains($m, 'certificate')
            || str_contains($m, 'handshake')
            || str_contains($m, 'openssl')
            || str_contains($m, 'crypto')
            || str_contains($m, 'starttls')
            || preg_match('/\btls\b|\bssl\b/', $m) === 1
                => 'tls',

            str_contains($m, 'expected response code')
            || preg_match('/\b[45]\d\d\b/', $m) === 1
                => 'smtp',

            default => 'unknown',
        };

        $message = match ($category) {
            'dns' => "Could not resolve SMTP host \"{$host}\" — check the server address, or use its IP directly.",
            'refused' => "The SMTP server at {$endpoint} refused the connection — nothing accepts connections on that port (wrong port, or the mail service is down).",
            'timeout' => "Connection to {$endpoint} timed out — traffic is being dropped on the way (firewall, VLAN routing, or the server is offline).",
            'auth' => "The SMTP server at {$endpoint} rejected the credentials — check the username and password.",
            'tls' => "Secure connection to {$endpoint} failed (encryption: {$encryption}) — check that the encryption setting matches the port (587 ↔ TLS, 465 ↔ SSL) and that the certificate matches the host name (certificates rarely match bare IPs).",
            'smtp' => "The SMTP server at {$endpoint} rejected the message.",
            default => "Sending through {$endpoint} failed.",
        };

        if ($raw !== '') {
            $message .= ' (raw: '.Str::limit($raw, 300).')';
        }

        return ['category' => $category, 'message' => $message];
    }

    /** The classified message alone — what UIs and error columns store. */
    public static function message(\Throwable $e, array $mail): string
    {
        return self::classify($e, $mail)['message'];
    }
}
