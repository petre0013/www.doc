<?php

use App\Support\Smtp\ErrorClassifier;

function classified(string $raw): array
{
    // Pest helpers share one global namespace — a plain function beats a
    // collision-prone bare constant for the fixture config.
    return ErrorClassifier::classify(
        new RuntimeException($raw),
        ['host' => '192.0.2.27', 'port' => 587, 'encryption' => 'tls'],
    );
}

test('it maps raw transport failures to distinct categories', function (string $raw, string $category) {
    expect(classified($raw)['category'])->toBe($category);
})->with([
    'dns' => ['Connection could not be established with host "tcp://smtp.foo:587": getaddrinfo for smtp.foo failed: Name or service not known', 'dns'],
    'refused' => ['stream_socket_client(): Unable to connect to tcp://192.0.2.27:587 (Connection refused)', 'refused'],
    'timeout' => ['stream_socket_client(): Unable to connect to tcp://192.0.2.27:587 (Connection timed out)', 'timeout'],
    'auth by word' => ['Failed to authenticate on SMTP server with username "docs"', 'auth'],
    'auth by code' => ['Expected response code 235 but got code "535", with message "535 5.7.8 Username and Password not accepted"', 'auth'],
    'tls certificate' => ['stream_socket_enable_crypto(): SSL operation failed with code 1: certificate verify failed', 'tls'],
    'tls starttls' => ['Unable to connect with STARTTLS: expected response code 220 but got 454', 'tls'],
    'smtp policy' => ['Expected response code 250 but got code "550", with message "550 relaying denied"', 'smtp'],
    'unknown' => ['something entirely unexpected happened', 'unknown'],
]);

test('the message always names what was attempted and keeps the raw error', function () {
    $r = classified('stream_socket_client(): Unable to connect to tcp://192.0.2.27:587 (Connection timed out)');

    expect($r['message'])->toContain('192.0.2.27:587')
        ->and($r['message'])->toContain('firewall')
        ->and($r['message'])->toContain('(raw: stream_socket_client()');
});

test('refused and timeout never collapse into one hint', function () {
    $refused = classified('(Connection refused)')['message'];
    $timeout = classified('(Connection timed out)')['message'];

    expect($refused)->toContain('refused')->not->toContain('timed out —');
    expect($timeout)->toContain('timed out')->not->toContain('refused');
});

test('the TLS diagnosis names the encryption mode and the port pairing', function () {
    $r = classified('certificate verify failed');

    expect($r['message'])->toContain('encryption: tls')
        ->and($r['message'])->toContain('587 ↔ TLS, 465 ↔ SSL');
});

test('a very long raw error is truncated, not stored verbatim', function () {
    $r = classified(str_repeat('x', 2000));

    expect(strlen($r['message']))->toBeLessThan(600);
});

test('missing config keys degrade gracefully', function () {
    $r = ErrorClassifier::classify(new RuntimeException('Connection refused'), []);

    expect($r['category'])->toBe('refused')
        ->and($r['message'])->toContain('?:0');
});
