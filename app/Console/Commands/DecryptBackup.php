<?php

namespace App\Console\Commands;

use App\Services\Backup\ArchiveCipher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Decrypt an encrypted backup archive to a plain `.zip` using only the
 * encryption key — no database, queue or running app required. This is the
 * "host is gone" recovery path: with the surviving `.zip.enc` and the
 * separately-escrowed key, an operator gets the readable PDFs back on any
 * machine that has this codebase + the key.
 */
#[Signature('backup:decrypt
    {source : Path to the encrypted .zip.enc archive}
    {--out= : Output .zip path (default: the source without its .enc suffix)}
    {--key= : base64 encryption key (defaults to BACKUP_ENCRYPTION_KEY)}')]
#[Description('Decrypt an encrypted backup archive to a plain .zip')]
class DecryptBackup extends Command
{
    public function handle(): int
    {
        $source = $this->argument('source');
        if (! is_file($source)) {
            $this->error("No such file: {$source}");

            return self::FAILURE;
        }

        $out = $this->option('out') ?: preg_replace('/\.enc$/', '', $source);
        if ($out === $source) {
            $out .= '.zip'; // never overwrite the source in place
        }

        try {
            $cipher = $this->option('key')
                ? new ArchiveCipher($this->decodeKey($this->option('key')))
                : ArchiveCipher::fromConfig();

            $cipher->decryptFile($source, $out);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Decrypted to {$out}");
        $this->line('Unzip it and open readable/<workspace>/<page>.pdf in any viewer — no app or database needed.');

        return self::SUCCESS;
    }

    private function decodeKey(string $b64): string
    {
        $key = base64_decode($b64, true);
        if ($key === false) {
            throw new \RuntimeException('--key is not valid base64.');
        }

        return $key;
    }
}
