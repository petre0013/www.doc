<?php

namespace App\Services\Backup\Destinations;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\Exception\AccessDeniedException;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\AuthenticationException;
use Icewind\SMB\Exception\ConnectException;
use Icewind\SMB\Exception\ConnectionRefusedException;
use Icewind\SMB\Exception\DependencyException;
use Icewind\SMB\Exception\ForbiddenException;
use Icewind\SMB\Exception\HostDownException;
use Icewind\SMB\Exception\InvalidHostException;
use Icewind\SMB\Exception\NoRouteToHostException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\Exception\TimedOutException;
use Icewind\SMB\IShare;
use Icewind\SMB\ServerFactory;

/**
 * Writes backups to an SMB/Windows network share (e.g. \\192.0.2.100\backup\docs
 * → host=192.0.2.100, share=backup, path=docs). Uses icewind/smb, which needs
 * the `smbclient` binary (or libsmbclient ext) present in the container image.
 *
 * @param array{host:string,share:string,path:string,username:string,password:string,domain:string} cfg
 */
class SmbDestination implements Destination
{
    /** @var array{host:string,share:string,path:string,username:string,password:string,domain:string} */
    private readonly array $cfg;

    public function __construct(array $cfg)
    {
        // Optional fields (username/password/domain/path) persist as null when
        // left blank; BasicAuth and the path helpers require strings, so coerce
        // every field once here — covers both the saved and "test typed" paths.
        $this->cfg = [
            'host'     => (string) ($cfg['host'] ?? ''),
            'share'    => (string) ($cfg['share'] ?? ''),
            'path'     => (string) ($cfg['path'] ?? ''),
            'username' => (string) ($cfg['username'] ?? ''),
            'password' => (string) ($cfg['password'] ?? ''),
            'domain'   => (string) ($cfg['domain'] ?? ''),
        ];
    }

    public function store(string $localZipPath, string $name): array
    {
        $share = $this->share();
        $this->ensureDir($share);
        $remote = $this->remotePath($name);
        $share->put($localZipPath, $remote);

        return ['path' => $remote, 'size' => (int) filesize($localZipPath)];
    }

    public function fetch(string $path): string
    {
        $tmp = sys_get_temp_dir() . '/' . uniqid('wwwdoc_fetch_') . '.zip';
        $this->share()->get($path, $tmp);

        return $tmp;
    }

    public function delete(string $path): void
    {
        try {
            $this->share()->del($path);
        } catch (\Throwable) {
            // best-effort (retention pruning) — a missing/locked file must not throw
        }
    }

    public function exists(string $path): bool
    {
        try {
            $this->share()->stat($path);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test(): void
    {
        $local = sys_get_temp_dir() . '/' . uniqid('wwwdoc_probe_');
        file_put_contents($local, 'ok');

        try {
            $share = $this->share();
            $this->ensureDir($share);
            $probe = $this->remotePath('.probe-' . uniqid() . '.txt');
            $share->put($local, $probe);
            $share->stat($probe);   // confirm it landed
            $share->del($probe);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->friendly($e));
        } finally {
            @unlink($local);
        }
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private function share(): IShare
    {
        $auth   = new BasicAuth($this->cfg['username'], $this->cfg['domain'] ?: 'WORKGROUP', $this->cfg['password']);
        $server = (new ServerFactory())->createServer($this->cfg['host'], $auth);

        return $server->getShare($this->cfg['share']);
    }

    /** The sub-path within the share, normalised to forward slashes. */
    private function dir(): string
    {
        return trim(str_replace('\\', '/', $this->cfg['path']), '/');
    }

    private function remotePath(string $name): string
    {
        $dir = $this->dir();

        return $dir === '' ? $name : "{$dir}/{$name}";
    }

    /** mkdir -p the configured sub-path, tolerating segments that already exist. */
    private function ensureDir(IShare $share): void
    {
        $dir = $this->dir();
        if ($dir === '') {
            return;
        }

        $accum = '';
        foreach (explode('/', $dir) as $segment) {
            $accum = $accum === '' ? $segment : "{$accum}/{$segment}";
            try {
                $share->mkdir($accum);
            } catch (AlreadyExistsException) {
                // fine — directory is already there
            } catch (\Throwable $e) {
                // some servers throw a generic error for existing dirs; only
                // rethrow if the path genuinely isn't there.
                try {
                    $share->stat($accum);
                } catch (\Throwable) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Same conventions as Smtp\ErrorClassifier: say WHAT WAS ATTEMPTED (host,
     * share, path), keep distinct failures distinct where the driver actually
     * lets us, and append the raw driver text for forensics.
     *
     * icewind/smb throws ConnectionRefusedException (a ConnectException
     * subclass) for BOTH a bad password and a nonexistent share — the SMB
     * tree-connect step fails the same way either way, so AuthenticationException
     * is never actually thrown at this stage (confirmed empirically against a
     * real server, 2026-07-09 QA). Rather than claim precision the protocol
     * doesn't give us, that case gets one honest combined message. The other
     * arms below (AuthenticationException, NotFoundException, …) are kept for
     * paths where icewind DOES distinguish — e.g. a missing sub-path once
     * already connected to a valid share — so this match must stay ordered
     * with ConnectionRefusedException before the generic ConnectException.
     */
    private function friendly(\Throwable $e): string
    {
        $host = $this->cfg['host'];
        $unc = '\\\\' . $host . '\\' . $this->cfg['share']
            . ($this->dir() !== '' ? '\\' . str_replace('/', '\\', $this->dir()) : '');

        $message = match (true) {
            $e instanceof DependencyException   => 'The server is missing the smbclient client needed to reach SMB shares. Install `smbclient` in the app image.',
            $e instanceof AuthenticationException => "The SMB host {$host} rejected the credentials — check the username, password and domain.",
            $e instanceof ForbiddenException,
            $e instanceof AccessDeniedException => "Connected to {$unc}, but the account cannot write there — check the share permissions for this user.",
            $e instanceof NotFoundException     => "The share or path {$unc} was not found on {$host} — check the share name and sub-path.",
            $e instanceof InvalidHostException  => "Could not resolve the SMB host \"{$host}\" — check the address, or use its IP directly.",
            $e instanceof NoRouteToHostException => "No route to {$host} — the network cannot deliver packets there (routing/gateway problem).",
            $e instanceof HostDownException     => "The SMB host {$host} is not responding — is it online?",
            $e instanceof TimedOutException     => "Connection to {$host} timed out — traffic is being dropped on the way (firewall, VLAN routing, or the host is offline).",
            $e instanceof ConnectionRefusedException => "Could not connect to {$unc} — check the share name AND the username/password (the server rejects the connection either way, without saying which is wrong).",
            $e instanceof ConnectException      => "Could not reach {$host} — check the address, that port 445 is open, and that the share is online.",
            default                             => "SMB operation against {$unc} failed.",
        };

        $raw = trim($e->getMessage());

        return $raw === '' ? $message : $message . ' (raw: ' . \Illuminate\Support\Str::limit($raw, 300) . ')';
    }
}
