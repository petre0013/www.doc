<?php

use App\Support\Smtp\SmtpProbe;

/**
 * Spawn tests/Support/fake-smtp-server.php in a child PHP process and block
 * until it prints the ephemeral port it bound. Registers cleanup on the
 * calling test.
 */
function fakeSmtp(string $scenario): int
{
    $proc = proc_open(
        [PHP_BINARY, __DIR__.'/../Support/fake-smtp-server.php', $scenario],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($proc)->not->toBeFalse();

    $line = fgets($pipes[1]); // blocks until the server is listening
    expect($line)->toMatch('/^PORT=\d+/');

    // Kill the child even if the test fails before the dialogue completes.
    test()->beforeApplicationDestroyed(function () use ($proc, $pipes) {
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        @proc_terminate($proc);
        @proc_close($proc);
    });

    return (int) substr(trim($line), 5);
}

/** Probe with test-friendly (short) timeouts. */
function probe(): SmtpProbe
{
    return new SmtpProbe(connectTimeout: 3, readTimeout: 2);
}

/** The report entry for one stage. */
function stageOf(array $stages, string $name): array
{
    $found = array_values(array_filter($stages, fn ($s) => $s['stage'] === $name));
    expect($found)->toHaveCount(1);

    return $found[0];
}

test('it passes plaintext stages against a live server and skips DNS for an IP literal', function () {
    $port = fakeSmtp('plain');

    $stages = probe()->run('127.0.0.1', $port, 'none');

    expect(array_column($stages, 'stage'))->toBe(['dns', 'connect', 'tls', 'send']);
    expect(stageOf($stages, 'dns'))->toMatchArray(['status' => 'skipped'])
        ->and(stageOf($stages, 'dns')['detail'])->toContain('IP address');
    expect(stageOf($stages, 'connect'))->toMatchArray(['status' => 'ok'])
        ->and(stageOf($stages, 'connect')['detail'])->toContain('220 fake.test');
    expect(stageOf($stages, 'tls'))->toMatchArray(['status' => 'skipped'])
        ->and(stageOf($stages, 'tls')['detail'])->toContain('plaintext');
    expect(SmtpProbe::failed($stages))->toBeFalse();
});

test('it reports the delegated send stage: success and failure', function () {
    $ok = probe()->run('127.0.0.1', fakeSmtp('plain'), 'none', fn () => null);
    expect(stageOf($ok, 'send'))->toMatchArray(['status' => 'ok']);

    $bad = probe()->run('127.0.0.1', fakeSmtp('plain'), 'none', function () {
        throw new RuntimeException('535 5.7.8 Authentication credentials invalid');
    });
    expect(stageOf($bad, 'send'))->toMatchArray(['status' => 'failed'])
        ->and(stageOf($bad, 'send')['detail'])->toContain('535');
});

test('it distinguishes connection refused from a timeout', function () {
    // Bind an ephemeral port, then close it: nothing listens there anymore.
    $server = stream_socket_server('tcp://127.0.0.1:0', $e, $m);
    $port = (int) substr(stream_socket_get_name($server, false), strrpos(stream_socket_get_name($server, false), ':') + 1);
    fclose($server);

    $stages = probe()->run('127.0.0.1', $port, 'none');

    $connect = stageOf($stages, 'connect');
    expect($connect['status'])->toBe('failed')
        ->and(strtolower($connect['detail']))->toContain('refused')
        ->and(strtolower($connect['detail']))->not->toContain('timed out');
    expect(stageOf($stages, 'tls'))->toMatchArray(['status' => 'skipped']);
    expect(stageOf($stages, 'send'))->toMatchArray(['status' => 'skipped']);
});

test('it fails the connect stage when a listening port sends no SMTP greeting', function () {
    $port = fakeSmtp('silent');

    $start = microtime(true);
    $stages = probe()->run('127.0.0.1', $port, 'none');
    $elapsed = microtime(true) - $start;

    $connect = stageOf($stages, 'connect');
    expect($connect['status'])->toBe('failed')
        ->and($connect['detail'])->toContain('no SMTP greeting');
    // Bounded: one read timeout (2s) plus slack, never a hung request.
    expect($elapsed)->toBeLessThan(5.0);
});

test('it flags a non-SMTP greeting as the wrong service', function () {
    $stages = probe()->run('127.0.0.1', fakeSmtp('garbage'), 'none');

    $connect = stageOf($stages, 'connect');
    expect($connect['status'])->toBe('failed')
        ->and($connect['detail'])->toContain('HTTP/1.1')
        ->and($connect['detail'])->toContain('different service');
});

test('it fails DNS for an unresolvable host and skips everything after', function () {
    // .invalid is RFC-reserved: guaranteed NXDOMAIN.
    $stages = probe()->run('smtp.does-not-exist.invalid', 25, 'tls');

    expect(stageOf($stages, 'dns'))->toMatchArray(['status' => 'failed'])
        ->and(stageOf($stages, 'dns')['detail'])->toContain('smtp.does-not-exist.invalid');
    expect(stageOf($stages, 'connect'))->toMatchArray(['status' => 'skipped']);
    expect(stageOf($stages, 'tls'))->toMatchArray(['status' => 'skipped']);
    expect(stageOf($stages, 'send'))->toMatchArray(['status' => 'skipped']);
});

test('it fails the TLS stage when the server refuses STARTTLS', function () {
    $stages = probe()->run('127.0.0.1', fakeSmtp('starttls-refused'), 'tls');

    expect(stageOf($stages, 'connect'))->toMatchArray(['status' => 'ok']);
    $tls = stageOf($stages, 'tls');
    expect($tls['status'])->toBe('failed')
        ->and($tls['detail'])->toContain('454')
        ->and($tls['detail'])->toContain('advertised STARTTLS but refused it');
    expect(stageOf($stages, 'send'))->toMatchArray(['status' => 'skipped']);
});

test('it fails the TLS stage with a pairing hint when implicit TLS meets a plaintext server', function () {
    $stages = probe()->run('127.0.0.1', fakeSmtp('plain'), 'ssl');

    expect(stageOf($stages, 'connect'))->toMatchArray(['status' => 'ok']);
    $tls = stageOf($stages, 'tls');
    expect($tls['status'])->toBe('failed')
        ->and($tls['detail'])->toContain('TLS handshake failed')
        ->and($tls['detail'])->toContain('switch encryption to TLS');
});
