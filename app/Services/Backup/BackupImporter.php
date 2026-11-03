<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\Backup\Destinations\DestinationFactory;
use ZipArchive;

/**
 * Registers an UPLOADED archive into the backups list so it can be restored via
 * the existing flow — the counterpart to BackupService (which builds archives).
 *
 * The uploaded file may have been encrypted with a key other than this host's
 * BACKUP_ENCRYPTION_KEY (a backup carried over from another instance). We:
 *
 *  - Read its manifest.json (counts/format) to describe the row in the UI.
 *  - NORMALISE the stored form so RestoreService — which only ever decrypts with
 *    the host's env key — can consume it later: re-encrypt the same inner zip
 *    under the env key when one is configured, otherwise store the plaintext zip.
 *  - If it's encrypted and we can't decrypt it (wrong/absent key), register it
 *    ANYWAY, verbatim, flagged `undecryptable` so the UI warns and blocks restore.
 *
 * The supplied key is used transiently here and NEVER persisted (same invariant
 * as ArchiveCipher: the key lives only in env).
 */
class BackupImporter
{
    public function import(Backup $backup, string $stagingPath, ?string $key): void
    {
        $destination = DestinationFactory::make($backup->disk);
        $name = 'import-' . now()->format('Ymd-His') . "-{$backup->id}.zip";

        try {
            // Plaintext archive: read the manifest directly and store as-is.
            if (! ArchiveCipher::isEncrypted($stagingPath)) {
                $manifest = $this->readManifest($stagingPath);
                $stored = $destination->store($stagingPath, $name);

                $this->finish($backup, $stored, $this->withEncryption($manifest, false, null));

                return;
            }

            // Encrypted archive — try the supplied key, then the host env key.
            $plain = $this->tryDecrypt($stagingPath, $key);

            if ($plain === null) {
                // Couldn't decrypt: keep the encrypted blob verbatim and flag it so
                // the UI shows a "could not decrypt" warning and disables restore.
                $stored = $destination->store($stagingPath, "{$name}.enc");

                $this->finish($backup, $stored, ['encryption' => ['enabled' => true, 'undecryptable' => true]]);

                return;
            }

            try {
                $manifest = $this->readManifest($plain);

                // Normalise for the env-key-only restore path.
                if (ArchiveCipher::configured()) {
                    $enc = $plain . '.enc';
                    ArchiveCipher::fromConfig()->encryptFile($plain, $enc);
                    try {
                        $stored = $destination->store($enc, "{$name}.enc");
                    } finally {
                        @unlink($enc);
                    }
                    $manifest = $this->withEncryption($manifest, true, ArchiveCipher::currentFingerprint());
                } else {
                    $stored = $destination->store($plain, $name);
                    $manifest = $this->withEncryption($manifest, false, null);
                }

                $this->finish($backup, $stored, $manifest);
            } finally {
                @unlink($plain);
            }
        } finally {
            @unlink($stagingPath);
        }
    }

    /**
     * Decrypt $src to a sibling temp file, trying the supplied key first and then
     * the host env key. Returns the plaintext path, or null if nothing worked.
     */
    private function tryDecrypt(string $src, ?string $key): ?string
    {
        $dest = $src . '.plain';

        $ciphers = [];
        if ($key !== null && trim($key) !== '') {
            try {
                $ciphers[] = ArchiveCipher::fromKey($key);
            } catch (\Throwable) {
                // Malformed key (bad base64 / wrong length) — skip, maybe env works.
            }
        }
        if (ArchiveCipher::configured()) {
            $ciphers[] = ArchiveCipher::fromConfig();
        }

        foreach ($ciphers as $cipher) {
            try {
                $cipher->decryptFile($src, $dest);

                return $dest;
            } catch (\Throwable) {
                @unlink($dest);
            }
        }

        return null;
    }

    /** Read + validate manifest.json (at the zip root) from a plaintext archive. */
    private function readManifest(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('The uploaded file is not a readable zip archive.');
        }

        try {
            $raw = $zip->getFromName('manifest.json');
        } finally {
            $zip->close();
        }

        if ($raw === false) {
            throw new \RuntimeException('This does not look like a www.doc backup (manifest.json is missing).');
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ! isset($manifest['counts'])) {
            throw new \RuntimeException('The backup manifest is invalid or unreadable.');
        }

        // RestoreService::canonicalRows understands format 1 (single JSON arrays)
        // and 2 (streamed NDJSON) — refuse anything newer we can't rebuild.
        $format = (int) ($manifest['format_version'] ?? 0);
        if (! in_array($format, [1, 2], true)) {
            throw new \RuntimeException("Unsupported backup format version ({$format}).");
        }

        return $manifest;
    }

    /** Overwrite the manifest's encryption block to reflect the STORED form. */
    private function withEncryption(array $manifest, bool $enabled, ?string $fingerprint): array
    {
        $manifest['encryption'] = [
            'enabled'     => $enabled,
            'cipher'      => $enabled ? 'xchacha20poly1305-secretstream' : null,
            'fingerprint' => $fingerprint,
        ];

        return $manifest;
    }

    /** @param array{path:string,size:int} $stored */
    private function finish(Backup $backup, array $stored, array $manifest): void
    {
        $backup->update([
            'status'      => 'done',
            'path'        => $stored['path'],
            'size_bytes'  => $stored['size'],
            'manifest'    => $manifest,
            'finished_at' => now(),
        ]);
    }
}
