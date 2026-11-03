<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Registers an uploaded archive into the backups list (BackupImporter). Heavy I/O
 * (decrypt / re-encrypt / store to an off-host share), so it runs on the queue and
 * the admin UI polls the `backups` row for progress — mirrors RunBackupJob.
 *
 * The decryption key (if the operator supplied one) rides the queue payload; it's
 * used only for this job and never written to the row.
 */
class ImportBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $backupId,
        public readonly string $stagingPath,
        public readonly ?string $key = null,
    ) {}

    public function handle(BackupImporter $importer): void
    {
        $backup = Backup::findOrFail($this->backupId);
        $backup->update(['status' => 'processing', 'started_at' => now()]);

        try {
            $importer->import($backup, $this->stagingPath, $this->key);

            // Actor + IP ride the row: the upload request is long gone by now.
            \App\Support\Audit::record('backup.imported', $backup, [
                'undecryptable' => (bool) ($backup->refresh()->manifest['encryption']['undecryptable'] ?? false),
            ], $backup->created_by_id, $backup->ip);
        } catch (Throwable $e) {
            // A bad upload (not a www.doc archive, unreadable zip) is a user-data
            // error recorded on the row for the UI — not an infra fault to retry,
            // so we don't re-throw (which would just fail the job + 500 the sync
            // request). BackupImporter cleans up the staging file in its finally.
            $backup->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            report($e);
        }
    }

    /**
     * Final-failure hook — the catch above swallows importer errors, so this
     * only fires for deaths it never sees (the 600s timeout kills the worker).
     * Marks the row failed instead of leaving it stuck in `processing`, and
     * drops the staged upload the importer's finally never reached.
     */
    public function failed(?Throwable $e): void
    {
        $backup = Backup::find($this->backupId);

        if ($backup && in_array($backup->status, ['pending', 'processing'], true)) {
            $backup->update([
                'status'      => 'failed',
                'error'       => $e?->getMessage() ?? 'The import was interrupted.',
                'finished_at' => now(),
            ]);
        }

        @unlink($this->stagingPath);
    }
}
