<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:prune
    {--days= : Keep events newer than this many days (defaults to the audit.retention_days setting, 365)}
    {--dry-run : Report what would be removed without deleting anything}')]
#[Description('Delete audit events past the retention window')]
class PruneAuditEvents extends Command
{
    public const DEFAULT_RETENTION_DAYS = 365;

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) (Setting::get('audit', [])['retention_days'] ?? self::DEFAULT_RETENTION_DAYS);

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = AuditEvent::query()->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would prune {$query->count()} audit events older than {$cutoff->toDateString()} ({$days} days).");

            return self::SUCCESS;
        }

        // Query-builder delete on purpose: the model throws on delete to keep
        // the trail append-only — retention pruning is the ONE sanctioned path.
        $pruned = $query->delete();

        $this->info("Pruned {$pruned} audit events older than {$cutoff->toDateString()} ({$days} days).");

        return self::SUCCESS;
    }
}
