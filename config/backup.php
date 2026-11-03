<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Archive format version
    |--------------------------------------------------------------------------
    | Written into each backup's manifest. Bump when the canonical layout
    | changes so a future RestoreService can branch on it. v2 streams the
    | documents/versions tables as NDJSON (v1 dumped them as single JSON arrays;
    | RestoreService still reads v1 archives by falling back to the .json files).
    */
    'format_version' => 2,

    /*
    |--------------------------------------------------------------------------
    | Scheduled cadences
    |--------------------------------------------------------------------------
    | The admin picks one of these in the Backups tab; `backup:run` (scheduled
    | hourly) dispatches a run once this many hours have elapsed since the last
    | successful scheduled backup.
    */
    'intervals' => [
        'daily'  => 24,
        '2days'  => 48,
        'weekly' => 168,
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults (until an admin saves settings)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'enabled'   => false,
        'interval'  => 'daily',
        'retention' => 7, // keep this many most-recent backups, prune older
    ],

    /*
    |--------------------------------------------------------------------------
    | Destination drivers
    |--------------------------------------------------------------------------
    | Where a backup may be written. `local` is the private disk — survives
    | container restarts (when app-storage is mounted) but NOT host loss. `smb`
    | writes to a Windows/SMB network share (e.g. \\192.168.100.100\backup\docs),
    | the off-host resilience milestone. Never `public`.
    */
    'drivers' => ['local', 'smb'],

    /*
    |--------------------------------------------------------------------------
    | Archive encryption at rest (NIS2)
    |--------------------------------------------------------------------------
    | A base64-encoded 32-byte key. When set AND the admin enables encryption in
    | the Backups tab, BackupService encrypts the whole archive with libsodium's
    | secretstream (XChaCha20-Poly1305 AEAD) before storing it; RestoreService
    | decrypts transparently, and `php artisan backup:decrypt` recovers it with
    | only the key — no app/DB. Generate one with ArchiveCipher::generateKey().
    | KEEP THIS OFF THE HOST/SHARE (a secrets vault) — an archive plus a key that
    | sat on the lost host is no protection at all.
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

];
