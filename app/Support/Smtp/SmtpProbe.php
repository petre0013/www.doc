<?php

namespace App\Support\Smtp;

/**
 * Staged SMTP connection diagnostics behind the admin "Send test email"
 * buttons. Instead of one opaque "could not reach the SMTP server", the
 * connection runs as explicit stages — DNS → TCP+banner → TLS → send — and the
 * report names the exact layer that failed (connection REFUSED points at a
 * wrong port or downed service; a TIMEOUT points at a firewall or VLAN
 * routing; a certificate error points at TLS-vs-IP mismatches).
 *
 * Stages 1–3 are raw-socket checks owned here; the final stage delegates to
 * the caller (the real Symfony mailer via MailTester / BackupNotifier), so
 * AUTH mechanics are never re-implemented. Every read/connect is
 * timeout-bounded — a black-holed host costs one connect timeout, not a hung
 * request. Interactive admin tests only: queue jobs must never probe (design:
 * .locals/docs/designs/2026-07-smtp-staged-diagnostics.md).
 *
 * The report is presentation-free data; stage labels/icons live client-side
 * (mirroring the auditEvents.js pattern):
 *
 *   [['stage' => 'dns'|'connect'|'tls'|'send',
 *     'status' => 'ok'|'failed'|'skipped',
 *     'detail' => human sentence, raw server/socket text included], ...]
 */
class SmtpProbe
{
    public function __construct(
        private int $connectTimeout = 8,
        private int $readTimeout = 6,
    ) {}

    /**
     * Probe host:port with the given encryption mode ('tls' = STARTTLS,
     * 'ssl' = implicit TLS, 'none' = plaintext). Always returns all four
     * stages; stages after a failure report as skipped.
     *
     * @param  callable|null  $send  performs the real test send (Symfony
     *         mailer); it runs on its OWN connection after the probe socket
     *         is closed, and its Throwable becomes the send stage's failure.
     * @param  bool  $verifyPeer  false skips certificate verification, exactly
     *         like the real mailer with the setting off — the probe must agree
     *         with what a real send would do.
     */
    public function run(string $host, int $port, string $encryption, ?callable $send = null, bool $verifyPeer = true): array
    {
        $stages = [$this->resolveDns($host)];

        $fp = null;
        if (! self::failed($stages)) {
            [$stages[], $fp] = $this->connect($host, $port, $encryption, $verifyPeer);
        } else {
            $stages[] = self::skipped('connect');
        }

        if (! self::failed($stages)) {
            $stages[] = $this->negotiateTls($fp, $host, $encryption, $verifyPeer);
        } else {
            $stages[] = self::skipped('tls');
        }

        if ($fp) {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
        }

        if (self::failed($stages)) {
            $stages[] = self::skipped('send');
        } elseif ($send === null) {
            $stages[] = self::stage('send', 'skipped', 'No test message requested.');
        } else {
            $stages[] = $this->delegateSend($send);
        }

        return $stages;
    }

    /** True when any stage in a report failed. */
    public static function failed(array $stages): bool
    {
        return (bool) array_filter($stages, fn ($s) => $s['status'] === 'failed');
    }

    private function resolveDns(string $host): array
    {
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return self::stage('dns', 'skipped', "{$host} is an IP address — no lookup needed.");
        }

        // Every A/AAAA record, like Ssrf::assertPublicUrl (gethostbyname would
        // silently return the hostname itself on failure).
        $ips = array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );

        return $ips === []
            ? self::stage('dns', 'failed', "Could not resolve \"{$host}\" — check the server address, or use its IP directly.")
            : self::stage('dns', 'ok', "{$host} resolved to ".implode(', ', $ips).'.');
    }

    /** @return array{0: array, 1: ?resource} the stage plus the open socket */
    private function connect(string $host, int $port, string $encryption, bool $verifyPeer): array
    {
        $literal = trim($host, '[]');
        // IPv6 literals need brackets in the socket address.
        $target = str_contains($literal, ':') ? "[{$literal}]" : $literal;

        // peer_name pins the later TLS handshake's certificate check to what
        // the admin typed — an IP literal is verified as an IP, matching what
        // the real mailer will do. With verification off, mirror Symfony's
        // verify_peer=false stream options.
        $context = stream_context_create(['ssl' => $verifyPeer
            ? ['peer_name' => $literal]
            : ['peer_name' => $literal, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            "tcp://{$target}:{$port}", $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context
        );

        if (! $fp) {
            $why = match (true) {
                $errno === 111 || stripos($errstr, 'refused') !== false
                    => "connection refused — the machine is reachable but nothing accepts connections on port {$port} (wrong port, or the mail service is down).",
                $errno === 110 || stripos($errstr, 'timed out') !== false
                    => "connection timed out after {$this->connectTimeout}s — traffic to {$host}:{$port} is being dropped (firewall, VLAN routing, or wrong network).",
                $errno === 113 || stripos($errstr, 'no route') !== false
                    => 'no route to host — the network cannot deliver packets there (routing/gateway problem).',
                default => $errstr !== '' ? $errstr : 'unknown socket error.',
            };

            return [self::stage('connect', 'failed', "TCP connection to {$host}:{$port} failed: {$why} (raw: {$errstr} #{$errno})"), null];
        }

        stream_set_timeout($fp, $this->readTimeout);

        // Implicit TLS: the server speaks TLS first, so the banner can only be
        // read after the handshake (TLS stage).
        if ($encryption === 'ssl') {
            return [self::stage('connect', 'ok', "Connected to {$host}:{$port} (SMTP greeting is read after the TLS handshake with implicit TLS)."), $fp];
        }

        [$code, $reply] = $this->readReply($fp);

        // No data at all (readReply's parenthesised sentinels) vs data that
        // just isn't SMTP — different diagnoses.
        if ($code === null && str_starts_with($reply, '(')) {
            return [self::stage('connect', 'failed', "Connected to {$host}:{$port} but got no SMTP greeting within {$this->readTimeout}s {$reply}. Is an SMTP service really listening on this port?"), null];
        }

        if ($code !== 220) {
            return [self::stage('connect', 'failed', "Connected to {$host}:{$port} but the greeting is not SMTP (expected code 220): \"{$reply}\". This port may belong to a different service."), null];
        }

        return [self::stage('connect', 'ok', "Connected to {$host}:{$port} — server greeted: \"{$reply}\"."), $fp];
    }

    /** @param  resource  $fp */
    private function negotiateTls($fp, string $host, string $encryption, bool $verifyPeer): array
    {
        if ($encryption === 'none') {
            return self::stage('tls', 'skipped', 'Encryption is off — plaintext SMTP. Fine for a trusted internal relay, but do not use SMTP authentication over plaintext.');
        }

        $unverified = $verifyPeer ? '' : ' — certificate verification skipped, as configured';

        if ($encryption === 'ssl') {
            [$ok, $error] = $this->enableCrypto($fp);
            if (! $ok) {
                return self::stage('tls', 'failed', $this->cryptoFailure($host, $error)
                    .' If the server expects STARTTLS instead of implicit TLS, switch encryption to TLS (typical for port 587; SSL is typical for port 465).');
            }

            [$code, $reply] = $this->readReply($fp);
            if ($code !== 220) {
                return self::stage('tls', 'failed', "TLS handshake succeeded but no SMTP greeting followed (got: \"{$reply}\")." );
            }

            return self::stage('tls', 'ok', 'TLS established'.$this->protocol($fp).$unverified.", server greeted: \"{$reply}\".");
        }

        // STARTTLS: plaintext EHLO, upgrade, then the crypto handshake.
        [$code, $reply] = $this->command($fp, 'EHLO '.$this->ehloName());
        if ($code !== 250) {
            return self::stage('tls', 'failed', "Server rejected EHLO before STARTTLS (got: \"{$reply}\").");
        }

        $advertised = stripos($reply, 'STARTTLS') !== false;

        [$code, $reply] = $this->command($fp, 'STARTTLS');
        if ($code !== 220) {
            $hint = $advertised
                ? 'The server advertised STARTTLS but refused it.'
                : 'The server does not offer STARTTLS on this port — try encryption "none" (plaintext relay) or the implicit-TLS port with "SSL".';

            return self::stage('tls', 'failed', "STARTTLS refused (got: \"{$reply}\"). {$hint}");
        }

        [$ok, $error] = $this->enableCrypto($fp);
        if (! $ok) {
            return self::stage('tls', 'failed', $this->cryptoFailure($host, $error));
        }

        return self::stage('tls', 'ok', 'STARTTLS upgrade succeeded'.$this->protocol($fp).$unverified.'.');
    }

    private function delegateSend(callable $send): array
    {
        try {
            $send();

            return self::stage('send', 'ok', 'Authenticated (where configured) and the server accepted the test message.');
        } catch (\Throwable $e) {
            // Raw for now; the shared ErrorClassifier (milestone 2) will
            // categorize this into auth-vs-policy language.
            return self::stage('send', 'failed', $e->getMessage());
        }
    }

    /**
     * Enable client TLS on the socket, capturing the openssl warning text
     * (certificate mismatches etc. surface as PHP warnings, not returns).
     *
     * @param  resource  $fp
     * @return array{0: bool, 1: ?string}
     */
    private function enableCrypto($fp): array
    {
        $warning = null;
        set_error_handler(function ($severity, $message) use (&$warning) {
            $warning = $message;

            return true;
        });

        try {
            $ok = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }

        $warning = $warning !== null
            ? trim(preg_replace('/^stream_socket_enable_crypto\(\):\s*/', '', $warning))
            : null;

        return [$ok === true, $warning];
    }

    private function cryptoFailure(string $host, ?string $error): string
    {
        $raw = $error ?: 'handshake failed with no further detail';

        $hint = str_contains(strtolower($raw), 'certificate')
            ? " The certificate does not validate for \"{$host}\" — certificates rarely match bare IPs; use the server's DNS name. For a self-signed or internal-CA certificate, turn on \"Skip certificate verification\"."
            : '';

        return "TLS handshake failed: {$raw}.{$hint}";
    }

    /** @param  resource  $fp */
    private function protocol($fp): string
    {
        $proto = stream_get_meta_data($fp)['crypto']['protocol'] ?? null;

        return $proto ? " ({$proto})" : '';
    }

    /**
     * Write one SMTP command and read its (possibly multi-line) reply.
     *
     * @param  resource  $fp
     * @return array{0: ?int, 1: string}
     */
    private function command($fp, string $command): array
    {
        if (@fwrite($fp, $command."\r\n") === false) {
            return [null, '(connection closed while sending '.strtok($command, ' ').')'];
        }

        return $this->readReply($fp);
    }

    /**
     * Read one SMTP reply: "250-..." continuation lines up to the final
     * "250 ..." line. Bounded by the socket read timeout and a line cap. A
     * line that isn't SMTP-shaped at all stops the read immediately (this is
     * the wrong-service case — waiting for a final SMTP line would only time
     * out). "No data" outcomes return a parenthesised sentinel as the text so
     * callers can tell them from real (non-SMTP) server output.
     *
     * @param  resource  $fp
     * @return array{0: ?int, 1: string}  [status code or null, reply text /
     *         failure description]
     */
    private function readReply($fp): array
    {
        $lines = [];

        for ($i = 0; $i < 32; $i++) {
            $line = fgets($fp, 2048);

            if ($line === false) {
                $timedOut = stream_get_meta_data($fp)['timed_out'] ?? false;

                return [null, $timedOut ? '(read timed out — the server sent nothing)' : '(the server closed the connection)'];
            }

            $lines[] = rtrim($line, "\r\n");

            if (! preg_match('/^\d{3}/', $line) || preg_match('/^\d{3}(?: |$)/', $line)) {
                break;
            }
        }

        $reply = implode(' / ', $lines);
        $last = (string) end($lines);

        return [preg_match('/^\d{3}/', $last) ? (int) substr($last, 0, 3) : null, $reply];
    }

    private function ehloName(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    private static function skipped(string $stage): array
    {
        return self::stage($stage, 'skipped', 'Not attempted — fix the failing step above first.');
    }

    private static function stage(string $stage, string $status, string $detail): array
    {
        return ['stage' => $stage, 'status' => $status, 'detail' => $detail];
    }
}
