<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('assets:prune
    {--hours=24 : Keep assets newer than this many hours (upload→insert→save grace window)}
    {--dry-run : List what would be removed without deleting anything}')]
#[Description('Delete asset files no longer referenced by any document or version')]
class PruneAssets extends Command
{
    public function handle(): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        // An asset is "in use" if its path appears anywhere in a document's
        // content/HTML or any historical version's — images are referenced by
        // their /storage/<path> URL embedded in the TipTap JSON, not by FK.
        //
        // Notes on correctness:
        //  - `documents` is queried raw (no soft-delete scope) on purpose: a
        //    trashed page is restorable, so its images must survive.
        //  - The grace window spares a just-uploaded asset whose page hasn't been
        //    saved yet, so a sweep mid-edit can't delete an image about to be used.
        $orphans = Asset::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('documents')
                ->whereRaw("documents.content_html LIKE '%' || assets.path || '%'")
                ->orWhereRaw("documents.content::text LIKE '%' || assets.path || '%'"))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('document_versions')
                ->whereRaw("document_versions.content_html LIKE '%' || assets.path || '%'")
                ->orWhereRaw("document_versions.content::text LIKE '%' || assets.path || '%'"))
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned assets to prune.');

            return self::SUCCESS;
        }

        $bytes = 0;
        foreach ($orphans as $asset) {
            $bytes += $asset->size;
            $this->line(($dryRun ? '[dry-run] ' : '').'Pruning '.$asset->path);

            if (! $dryRun) {
                Storage::disk($asset->disk)->delete($asset->path);
                $asset->delete();
            }
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info("{$verb} {$orphans->count()} asset(s), ".round($bytes / 1_048_576, 2).' MB.');

        return self::SUCCESS;
    }
}
