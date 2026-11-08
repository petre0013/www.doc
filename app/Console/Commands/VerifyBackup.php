<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\RestoreService;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:verify {--backup= : Backup id (defaults to the newest completed backup)}')]
#[Description('Fetch, decrypt and integrity-check a backup archive and record the result')]
class VerifyBackup extends Command
{
    public function handle(RestoreService $restore): int
    {
        $backup = Backup::query()
            ->where('status', 'done')
            ->whereNotNull('path')
            ->where('trigger', '!=', 'pre-restore')
            ->when($this->option('backup'), fn ($q, $id) => $q->whereKey($id))
            ->latest('id')
            ->first();

        if (! $backup) {
            $this->info('No completed backup to verify.');

            return self::SUCCESS;
        }

        try {
            $result = $restore->verifyArchive($backup);
        } catch (\Throwable $e) {
            $backup->update(['verified_at' => now(), 'verify_status' => 'failed', 'verify_error' => $e->getMessage()]);
            Audit::record('backup.verify_failed', $backup, ['error' => $e->getMessage()]);
            $this->error("Backup {$backup->id} failed verification: {$e->getMessage()}");

            return self::FAILURE;
        }

        $backup->update(['verified_at' => now(), 'verify_status' => 'passed', 'verify_error' => null]);
        Audit::record('backup.verified', $backup, $result);
        $this->info("Backup {$backup->id} verified ({$result['files']} files, {$result['rows']} rows).");

        return self::SUCCESS;
    }
}
