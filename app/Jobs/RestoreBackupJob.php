<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\RestoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rebuilds the content model from a backup's canonical layer. Destructive and
 * heavy, so it runs on the queue. `restored_at` is tracked on the row's status
 * transitions (processing → restored | failed) for the admin UI.
 */
class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $backupId) {}

    public function handle(RestoreService $service): void
    {
        $backup = Backup::findOrFail($this->backupId);

        try {
            $service->restore($backup);
            $backup->update(['restore_status' => 'restored', 'restored_at' => now(), 'restore_error' => null]);

            // System event (no session on the queue); the requesting admin was
            // captured by backup.restore_requested. Recorded AFTER the restore
            // transaction, so it survives the wipe it describes.
            //
            // No IP override either, deliberately: the address travels with the
            // actor, and the row's `ip` is where this backup was CREATED, which
            // can be a different admin (or a scheduled run) than the one
            // restoring it. The requester's address is on restore_requested.
            \App\Support\Audit::record('backup.restored', $backup, [
                'trigger' => $backup->trigger,
                'path'    => $backup->path,
            ]);
        } catch (Throwable $e) {
            $backup->update(['restore_status' => 'failed', 'restore_error' => $e->getMessage()]);
            report($e);
            throw $e;
        }
    }

    /**
     * Final-failure hook — covers deaths the catch never sees (the 600s timeout
     * kills the worker), so `restore_status` can't stay stuck on `restoring`.
     */
    public function failed(?Throwable $e): void
    {
        $backup = Backup::find($this->backupId);

        if ($backup && $backup->restore_status === 'restoring') {
            $backup->update([
                'restore_status' => 'failed',
                'restore_error'  => $e?->getMessage() ?? 'The restore was interrupted.',
            ]);
        }
    }
}
