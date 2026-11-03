<?php

namespace App\Services\Backup\Destinations;

use App\Support\BackupSettings;

/** Resolves the active backup Destination from the saved `backup` settings. */
class DestinationFactory
{
    /** Build the destination for the configured driver (local | smb). */
    public static function make(?string $driver = null): Destination
    {
        $settings = BackupSettings::get();
        $driver ??= $settings['driver'] ?? 'local';

        return match ($driver) {
            'smb'   => new SmbDestination([
                ...$settings['smb'],
                'password' => BackupSettings::smbPassword(),
            ]),
            default => new LocalDestination(),
        };
    }

    /**
     * Build a destination from an explicit (already-decrypted) config — used by
     * the "Test connection" endpoint, which validates the values the admin just
     * typed before they are saved.
     *
     * @param array{host:string,share:string,path:string,username:string,password:string,domain:string} $smb
     */
    public static function smb(array $smb): Destination
    {
        return new SmbDestination($smb);
    }
}
