<?php

namespace App\Services\Backup\Destinations;

use Illuminate\Support\Facades\Storage;

/** The default destination: the private `local` disk (storage/app/private). */
class LocalDestination implements Destination
{
    private const DISK = 'local';

    public function store(string $localZipPath, string $name): array
    {
        $path = "backups/{$name}";
        Storage::disk(self::DISK)->writeStream($path, fopen($localZipPath, 'r'));

        return ['path' => $path, 'size' => (int) filesize($localZipPath)];
    }

    public function fetch(string $path): string
    {
        $tmp = sys_get_temp_dir() . '/' . uniqid('wwwdoc_fetch_') . '.zip';
        copy(Storage::disk(self::DISK)->path($path), $tmp);

        return $tmp;
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    public function test(): void
    {
        $probe = 'backups/.probe-' . uniqid();

        try {
            Storage::disk(self::DISK)->put($probe, 'ok');
            if (Storage::disk(self::DISK)->get($probe) !== 'ok') {
                throw new \RuntimeException('Probe file read-back mismatch.');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Local disk is not writable: ' . $e->getMessage());
        } finally {
            Storage::disk(self::DISK)->delete($probe);
        }
    }
}
