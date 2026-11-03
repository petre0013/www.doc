<?php

/**
 * Scripted SMTP peer for SmtpProbeTest — run via proc_open, NOT autoloaded.
 * Listens on an ephemeral 127.0.0.1 port, prints "PORT=<n>" once ready,
 * serves exactly ONE connection according to the argv[1] scenario, then exits.
 *
 * Scenarios:
 *   plain            — well-behaved plaintext server (220 banner, EHLO, QUIT)
 *   silent           — accepts the connection but never sends a byte
 *   garbage          — greets with a non-SMTP line (an HTTP response)
 *   starttls-refused — advertises STARTTLS on EHLO, then refuses it with 454
 */
$scenario = $argv[1] ?? 'plain';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
echo 'PORT='.substr($name, strrpos($name, ':') + 1)."\n";

$conn = @stream_socket_accept($server, 10);
if (! $conn) {
    exit(0);
}

$write = function (string $line) use ($conn): void {
    fwrite($conn, $line."\r\n");
};

if ($scenario === 'silent') {
    sleep(8); // longer than any test read timeout

    exit(0);
}

if ($scenario === 'garbage') {
    $write('HTTP/1.1 400 Bad Request');
    sleep(2);

    exit(0);
}

$write('220 fake.test ESMTP FakeSmtpServer');

while (($line = fgets($conn)) !== false) {
    $cmd = strtoupper(trim($line));

    if (str_starts_with($cmd, 'EHLO') || str_starts_with($cmd, 'HELO')) {
        if ($scenario === 'starttls-refused') {
            $write('250-fake.test');
            $write('250 STARTTLS');
        } else {
            $write('250 fake.test');
        }
    } elseif (str_starts_with($cmd, 'STARTTLS')) {
        $write('454 4.7.0 TLS not available due to local problem');
    } elseif (str_starts_with($cmd, 'QUIT')) {
        $write('221 bye');
        break;
    } else {
        $write('250 OK');
    }
}
