<?php

use App\Support\Ssrf;

test('it pins a hostname to its validated IPs on the default https port', function () {
    expect(Ssrf::resolvePin('https://example.com/logo.png', ['93.184.216.34']))
        ->toBe(['example.com:443:93.184.216.34']);
});

test('it defaults to port 80 for http', function () {
    expect(Ssrf::resolvePin('http://example.com/logo.png', ['93.184.216.34']))
        ->toBe(['example.com:80:93.184.216.34']);
});

test('it honours an explicit port', function () {
    expect(Ssrf::resolvePin('https://example.com:8443/logo.png', ['93.184.216.34']))
        ->toBe(['example.com:8443:93.184.216.34']);
});

test('it pins every validated IP so cURL cannot pick an unvalidated one', function () {
    expect(Ssrf::resolvePin('https://example.com/x', ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']))
        ->toBe(['example.com:443:93.184.216.34,2606:2800:220:1:248:1893:25c8:1946']);
});

test('it does not pin an IPv4-literal host (no DNS step to exploit)', function () {
    expect(Ssrf::resolvePin('http://93.184.216.34/logo.png', ['93.184.216.34']))->toBe([]);
});

test('it does not pin an IPv6-literal host', function () {
    expect(Ssrf::resolvePin('http://[2606:2800::1]/logo.png', ['2606:2800::1']))->toBe([]);
});

test('it returns no pin when there are no validated IPs', function () {
    expect(Ssrf::resolvePin('https://example.com/x', []))->toBe([]);
});
