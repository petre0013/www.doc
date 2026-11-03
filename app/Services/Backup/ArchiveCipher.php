<?php

namespace App\Services\Backup;

/**
 * Streaming authenticated encryption for backup archives at rest (NIS2).
 *
 * Uses libsodium's secretstream (XChaCha20-Poly1305) — an AEAD construction, so
 * it protects confidentiality AND integrity/authenticity: any tampering or
 * truncation is detected on decrypt (the final chunk carries a FINAL tag, so a
 * cut-short archive fails rather than silently restoring partial data). Chosen
 * over AES-256-GCM because PHP's openssl GCM can't stream — it would reload the
 * whole archive into memory, undoing the canonical layer's bounded-memory design.
 *
 * The key lives ONLY in env (BACKUP_ENCRYPTION_KEY, base64 of 32 bytes) — never
 * in the settings blob (which the DB dump would otherwise round-trip) and never
 * echoed to the UI. Keeping the key off the host/share is the operator's job and
 * the whole point: an archive on a lost host or a readable SMB share is useless
 * without the separately-escrowed key.
 *
 * On-disk format:  MAGIC | secretstream header (24B) | { uint32 len, ciphertext }*
 * The MAGIC lets `backup:decrypt` reject a plain (unencrypted) zip with a clear
 * error, and lets RestoreService detect encryption without consulting the DB.
 */
class ArchiveCipher
{
    /** Identifies an encrypted wwwdoc archive; the trailing digit is the format. */
    public const MAGIC = "WWWDOCBK\x01";

    /** Plaintext chunk size: 64 KiB keeps peak memory flat for any archive size. */
    private const CHUNK = 65536;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new \InvalidArgumentException('Backup encryption key must be exactly 32 bytes.');
        }
    }

    /** Build from BACKUP_ENCRYPTION_KEY (base64 of 32 bytes). */
    public static function fromConfig(): self
    {
        $b64 = config('backup.encryption_key');
        if (! $b64) {
            throw new \RuntimeException('BACKUP_ENCRYPTION_KEY is not set; cannot encrypt/decrypt backups.');
        }

        $key = base64_decode($b64, true);
        if ($key === false) {
            throw new \RuntimeException('BACKUP_ENCRYPTION_KEY is not valid base64.');
        }

        return new self($key);
    }

    /**
     * Build from an explicit base64 key — used by backup IMPORT, where the archive
     * may have been encrypted with a key other than this host's BACKUP_ENCRYPTION_KEY
     * (e.g. a backup carried over from another instance). Never touches env/settings.
     * Throws on malformed base64 or the wrong key length (constructor enforces 32B).
     */
    public static function fromKey(string $base64Key): self
    {
        $key = base64_decode(trim($base64Key), true);
        if ($key === false) {
            throw new \RuntimeException('The decryption key is not valid base64.');
        }

        return new self($key);
    }

    /** Whether a VALID 32-byte key is configured (drives the UI toggle + validation). */
    public static function configured(): bool
    {
        try {
            self::fromConfig();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Return a sha256 fingerprint of the current encryption key, or null if unset/invalid. */
    public static function currentFingerprint(): ?string
    {
        $b64 = config('backup.encryption_key');
        if (! $b64) return null;
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) return null;
        return hash('sha256', $key);
    }


    /** Generate a fresh base64 key for an operator to paste into their env. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** Encrypt $src into $dest, streamed in CHUNK-sized pieces. */
    public function encryptFile(string $src, string $dest): void
    {
        $in  = $this->open($src, 'rb');
        $out = $this->open($dest, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            fwrite($out, self::MAGIC);
            fwrite($out, $header);

            do {
                $chunk = fread($in, self::CHUNK);
                $eof   = feof($in);
                $tag   = $eof
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : 0;

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($out, pack('N', strlen($cipher)) . $cipher);
            } while (! $eof);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypt $src into $dest. Throws if the file isn't a wwwdoc archive, the key
     * is wrong, or the archive was tampered with / truncated.
     */
    public function decryptFile(string $src, string $dest): void
    {
        $in  = $this->open($src, 'rb');
        $out = $this->open($dest, 'wb');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('Not an encrypted wwwdoc backup (bad magic).');
            }

            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state  = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            if ($state === false) {
                throw new \RuntimeException('Could not initialise decryption (corrupt header or wrong key).');
            }

            $sawFinal = false;
            while (! feof($in)) {
                $lenRaw = fread($in, 4);
                if ($lenRaw === '' || strlen($lenRaw) < 4) {
                    break;
                }
                $len    = unpack('N', $lenRaw)[1];
                $cipher = $this->readExactly($in, $len);

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new \RuntimeException('Decryption failed: wrong key or tampered archive.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                    break;
                }
            }

            // No FINAL tag => the archive was truncated; refuse it.
            if (! $sawFinal) {
                throw new \RuntimeException('Decryption failed: archive is incomplete (truncated).');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** True if the file begins with our magic bytes. */
    public static function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            return fread($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
    }

    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$path}.");
        }

        return $handle;
    }

    /** fread can return short; loop until we have $len bytes (or hit EOF). */
    private function readExactly($handle, int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len && ! feof($handle)) {
            $buf .= fread($handle, $len - strlen($buf));
        }

        return $buf;
    }
}
