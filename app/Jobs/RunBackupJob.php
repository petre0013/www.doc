<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupNotifier;
use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds a backup archive off the web request (mirrors ExportDocumentJob).
 * The `backups` row is the progress record the admin UI polls.
 */
class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $backupId) {}

    public function handle(BackupService $service, BackupNotifier $notifier): void
    {
        $backup = Backup::findOrFail($this->backupId);
        $backup->update(['status' => 'processing', 'started_at' => now()]);

        try {
            $service->run($backup);
            $notifier->notify($backup->refresh());

            // Actor + IP override: no session and no request on the queue, so
            // both ride the backup row from the request that started it.
            // Scheduled runs have neither and land as system (null) events.
            \App\Support\Audit::record('backup.completed', $backup, [
                'trigger'    => $backup->trigger,
                'size_bytes' => $backup->size_bytes,
            ], $backup->created_by_id, $backup->ip);
        } catch (Throwable $e) {
            $backup->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $notifier->notify($backup->refresh());
            throw $e;
        }
    }

    /**
     * Final-failure hook — covers deaths the catch never sees (the 600s timeout
     * kills the worker, lost payload), so the row can't stay stuck in
     * `processing` with the admin UI polling forever. The status guard keeps a
     * failure the catch already recorded (and notified) from double-notifying.
     */
    public function failed(?Throwable $e): void
    {
        $backup = Backup::find($this->backupId);
        if (! $backup || ! in_array($backup->status, ['pending', 'processing'], true)) {
            return;
        }

        $backup->update([
            'status'      => 'failed',
            'error'       => $e?->getMessage() ?? 'The backup was interrupted.',
            'finished_at' => now(),
        ]);

        try {
            app(BackupNotifier::class)->notify($backup->refresh());
        } catch (Throwable $notifyError) {
            report($notifyError);
        }
    }
}
