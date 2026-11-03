<?php

use App\Services\Backup\Destinations\SmbDestination;
use Icewind\SMB\Exception\AuthenticationException;
use Icewind\SMB\Exception\ConnectException;
use Icewind\SMB\Exception\ConnectionRefusedException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\Exception\TimedOutException;

/**
 * SmbDestination::friendly() follows the Smtp\ErrorClassifier conventions:
 * name what was attempted (host/share/path), keep distinct failures distinct
 * WHERE THE DRIVER ACTUALLY LETS US, append the raw driver text. The mapper
 * is private and only reachable through live SMB I/O otherwise (not covered
 * by the suite — see backups rules), so a bound closure exercises it directly.
 *
 * The AuthenticationException/NotFoundException cases below are exercised for
 * completeness (the match arms exist for other code paths), but a real
 * tree-connect failure — bad password OR bad share — was confirmed against a
 * live samba container (2026-07-09 QA) to throw ConnectionRefusedException in
 * BOTH cases; that's covered by its own test below and is the one admins will
 * actually hit from "Test connection".
 */
function smbFriendly(\Throwable $e): string
{
    $dest = new SmbDestination(['host' => '192.0.2.100', 'share' => 'backup', 'path' => 'docs']);

    return Closure::bind(fn ($x) => $this->friendly($x), $dest, SmbDestination::class)($e);
}

test('auth failures name the host and keep the raw NT status', function () {
    $msg = smbFriendly(new AuthenticationException('NT_STATUS_LOGON_FAILURE'));

    expect($msg)->toContain('192.0.2.100')
        ->and($msg)->toContain('credentials')
        ->and($msg)->toContain('(raw: NT_STATUS_LOGON_FAILURE)');
});

test('a timeout is distinct from a generic connect failure', function () {
    $timeout = smbFriendly(new TimedOutException('timed out'));
    $connect = smbFriendly(new ConnectException('unreachable'));

    expect($timeout)->toContain('timed out')->toContain('firewall');
    expect($connect)->toContain('port 445')->not->toContain('firewall');
});

test('missing share/path failures spell out the attempted UNC path', function () {
    expect(smbFriendly(new NotFoundException('NT_STATUS_OBJECT_NAME_NOT_FOUND')))
        ->toContain('\\\\192.0.2.100\\backup\\docs');
});

test('a real tree-connect failure (bad password OR bad share) is one honest combined message, not a false "unreachable"', function () {
    $msg = smbFriendly(new ConnectionRefusedException(''));

    expect($msg)->toContain('\\\\192.0.2.100\\backup\\docs')
        ->and($msg)->toContain('share name AND the username/password')
        // Must NOT fall through to the generic ConnectException wording —
        // that would falsely suggest a network/port problem.
        ->and($msg)->not->toContain('port 445');
});

test('unmapped errors still say what was attempted plus the raw text', function () {
    expect(smbFriendly(new RuntimeException('weird driver explosion')))
        ->toContain('SMB operation against \\\\192.0.2.100\\backup\\docs failed')
        ->toContain('(raw: weird driver explosion)');
});
