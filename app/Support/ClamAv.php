<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Optional upload scanning against a clamd daemon (set CLAMAV_HOST to enable). */
class ClamAv
{
    /** Rejects an infected file. When enabled, an unreachable scanner also rejects it. */
    public function assertClean(string $path, string $field = 'file'): void
    {
        if (! config('services.clamav.host')) {
            return;
        }

        try {
            $reply = $this->scan($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'The malware scanner is unavailable, so this file was not accepted.']);
        }

        if (! str_ends_with(trim($reply, "\0\r\n "), 'OK')) {
            throw ValidationException::withMessages([$field => 'This file was rejected by the malware scanner.']);
        }
    }

    protected function scan(string $path): string
    {
        $socket = stream_socket_client(
            'tcp://' . config('services.clamav.host') . ':' . config('services.clamav.port'),
            $errno, $error, 5,
        );
        if ($socket === false) {
            throw new \RuntimeException($error);
        }

        stream_set_timeout($socket, 60);

        try {
            fwrite($socket, "zINSTREAM\0");
            $file = fopen($path, 'rb');
            while (! feof($file)) {
                $chunk = fread($file, 8192);
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }
            fclose($file);
            fwrite($socket, pack('N', 0));

            return (string) stream_get_contents($socket);
        } finally {
            fclose($socket);
        }
    }
}
