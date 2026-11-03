<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Support\BackupSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled hourly in routes/console.php; dispatches a backup only once the
 * admin-configured cadence has elapsed since the last successful scheduled run.
 * Runs in the existing `scheduler` service.
 */
#[Signature('backup:run')]
#[Description('Dispatch a scheduled backup if the configured interval has elapsed')]
class RunScheduledBackup extends Command
{
    public function handle(): int
    {
        $settings = BackupSettings::get();

        if (! ($settings['enabled'] ?? false)) {
            $this->info('Scheduled backups are disabled.');

            return self::SUCCESS;
        }

        // The cadence is either a preset key (daily/2days/weekly → hours from
        // config) or a custom interval stored directly as a number of hours.
        $interval = $settings['interval'] ?? 'daily';
        $hours = is_numeric($interval)
            ? max(1, (int) $interval)
            : config("backup.intervals.{$interval}", 24);

        $last = Backup::where('trigger', 'scheduled')
            ->where('status', 'done')
            ->latest('finished_at')
            ->first();

        if ($last?->finished_at && $last->finished_at->greaterThan(now()->subHours($hours))) {
            $this->info('Last scheduled backup is still within the configured interval — skipping.');

            return self::SUCCESS;
        }

        // Don't stack a second run on top of one still going. The 24h window
        // self-heals if a row was ever stranded in-flight (the jobs' failed()
        // hooks should prevent that, but a stuck row must not block forever).
        $inFlight = Backup::where('trigger', 'scheduled')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '>', now()->subDay())
            ->exists();

        if ($inFlight) {
            $this->info('A scheduled backup is already running — skipping.');

            return self::SUCCESS;
        }

        $backup = Backup::create([
            'trigger' => 'scheduled',
            'disk'    => $settings['driver'] ?? 'local',
            'status'  => 'pending',
        ]);

        RunBackupJob::dispatch($backup->id);

        $this->info("Dispatched scheduled backup #{$backup->id}.");

        return self::SUCCESS;
    }
}
