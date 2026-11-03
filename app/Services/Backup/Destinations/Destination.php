<?php

namespace App\Services\Backup\Destinations;

/**
 * Where a backup archive is written: the private `local` disk, or an SMB/Windows
 * network share. Selected per the admin's `driver` setting. Implementations must
 * keep paths relative to their own root (e.g. `backups/<name>.zip`).
 */
interface Destination
{
    /**
     * Store a freshly-built local zip under $name.
     *
     * @return array{path: string, size: int} the stored path + byte size
     */
    public function store(string $localZipPath, string $name): array;

    /** Download $path to a local temp file and return its path (caller deletes). */
    public function fetch(string $path): string;

    /** Remove a stored archive (best-effort; used by retention pruning). */
    public function delete(string $path): void;

    /** Check if the archive exists on the destination. */
    public function exists(string $path): bool;

    /**
     * Verify the destination is reachable and writable by creating, reading back
     * and deleting a probe file. Throws \RuntimeException with a human message on
     * any failure.
     */
    public function test(): void;
}
