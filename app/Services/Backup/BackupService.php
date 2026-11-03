<?php

namespace App\Services\Backup;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use App\Models\Link;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Services\Backup\Destinations\DestinationFactory;
use App\Services\Exporters\PdfExporter;
use App\Support\BackupSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Builds a backup archive: ONE `.zip` with two layers.
 *
 *  - canonical/  — the authoritative restore source: a dump of the whole content
 *    model (content jsonb VERBATIM) + every referenced asset binary, described by
 *    manifest.json (format/schema version, counts, per-file sha256). The two
 *    high-volume tables (documents, versions) are streamed as NDJSON — one JSON
 *    object per line — so peak memory stays flat regardless of page count; the
 *    small tables stay as single JSON files.
 *  - readable/   — PDF-per-page foldered by workspace/tree, for humans/auditors.
 *    Explicitly NON-authoritative; RestoreService never reads it. PDF (not DOCX)
 *    because canonical JSON owns restore, so this layer only needs to be read:
 *    PDF is portable, fixed-fidelity and the archival norm auditors expect.
 *
 * Heavy work, so it runs inside RunBackupJob on the queue. Writes to a PRIVATE
 * destination (`local` disk by default, or an SMB share off-host), never `public`.
 */
class BackupService
{
    /**
     * @param bool $canonicalOnly Skip the readable PDF layer. Used for the
     *   pre-restore safety snapshot, which only needs to be restorable (canonical)
     *   — not human-readable — so it stays fast and free of the Node/PDF dependency.
     */
    public function run(Backup $backup, bool $canonicalOnly = false): void
    {
        $work = $this->tempDir();

        try {
            File::ensureDirectoryExists("{$work}/canonical");
            File::ensureDirectoryExists("{$work}/assets");
            File::ensureDirectoryExists("{$work}/attachment-files");

            $counts = $this->writeCanonical($work);
            $this->writeAssets($work);
            $this->writeAttachments($work);

            if (! $canonicalOnly) {
                File::ensureDirectoryExists("{$work}/readable");
                $this->writeReadable($work);
            }

            $encrypt  = $this->shouldEncrypt();
            $manifest = $this->writeManifest($work, $counts, $encrypt);

            $zipPath = $this->zip($work);
            $stored  = $this->storeArchive($backup, $zipPath, $encrypt);

            $backup->update([
                'status'      => 'done',
                'path'        => $stored['path'],
                'size_bytes'  => $stored['size'],
                'manifest'    => $manifest,
                'finished_at' => now(),
            ]);

            @unlink($zipPath);

            // Pruning is housekeeping — the backup itself already succeeded, so
            // a rotation hiccup (e.g. an SMB error deleting an old archive) must
            // not flip this run's status to failed in the caller's catch.
            try {
                $this->pruneOldBackups($backup);
            } catch (\Throwable $e) {
                report($e);
            }
        } finally {
            File::deleteDirectory($work);
        }
    }

    // ── Canonical layer ───────────────────────────────────────────────────────

    /** @return array<string,int> counts by entity */
    private function writeCanonical(string $work): array
    {
        // Small, bounded tables: dump whole (users.json is a keyed map too).
        // withTrashed: a backup must capture the Trash too, so a restore is a
        // faithful point-in-time copy (soft-deleted rows included).
        $workspaces = Workspace::withTrashed()->orderBy('id')->get()
            ->map(fn (Workspace $w) => $w->makeVisible(['deleted_at'])->toArray());

        // Workspace groups (shelves). No soft-deletes — whole table. Restored
        // BEFORE workspaces (workspaces.group_id references them).
        $groups    = WorkspaceGroup::orderBy('id')->get()->map->toArray();

        // Page folders (containers inside a workspace). Restored AFTER workspaces
        // (document_folders.workspace_id) but BEFORE documents (documents.folder_id).
        $folders   = DocumentFolder::orderBy('id')->get()->map->toArray();

        $tags      = Tag::orderBy('id')->get()->map->toArray();
        $links     = Link::orderBy('id')->get()->map->toArray();
        $templates = Template::orderBy('id')->get()->map->toArray();

        // id → name/email/role map: enough to re-attribute authorship on restore
        // without dumping password hashes.
        $users = User::orderBy('id')->get()->mapWithKeys(fn (User $u) => [
            $u->id => [
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->getRoleNames()->first(),
            ],
        ]);

        $assets = Asset::orderBy('id')->get()->map->toArray();

        // Page attachments: page-scoped files. Restored after documents (FK).
        $attachments = Attachment::orderBy('id')->get()->map->toArray();

        $this->putJson("{$work}/canonical/workspaces.json", $workspaces);
        $this->putJson("{$work}/canonical/workspace_groups.json", $groups);
        $this->putJson("{$work}/canonical/document_folders.json", $folders);
        $this->putJson("{$work}/canonical/tags.json", $tags);
        $this->putJson("{$work}/canonical/links.json", $links);
        $this->putJson("{$work}/canonical/users.json", $users);
        $this->putJson("{$work}/canonical/assets.json", $assets);
        $this->putJson("{$work}/canonical/attachments.json", $attachments);
        $this->putJson("{$work}/canonical/templates.json", $templates);

        // High-volume tables stream to NDJSON (one JSON object per line) so peak
        // memory stays flat no matter how many pages/versions exist — each row is
        // encoded and flushed individually, never the whole collection at once.
        $documents = $this->putDocumentsNdjson("{$work}/canonical/documents.ndjson");
        $versions  = $this->putNdjson(
            "{$work}/canonical/versions.ndjson",
            DocumentVersion::lazyById(500)->map(fn (DocumentVersion $v) => $v->toArray()),
        );

        // Compliance data rides along. Restore MERGES these (insert-missing by
        // id) instead of wiping — the trail is append-only even across restores.
        $auditEvents = $this->putNdjson(
            "{$work}/canonical/audit_events.ndjson",
            AuditEvent::lazyById(500)->map(fn (AuditEvent $e) => $e->toArray()),
        );

        return [
            'workspaces' => $workspaces->count(),
            'workspace_groups' => $groups->count(),
            'document_folders' => $folders->count(),
            'documents'  => $documents,
            'versions'   => $versions,
            'tags'       => $tags->count(),
            'links'      => $links->count(),
            'users'      => $users->count(),
            'assets'     => $assets->count(),
            'attachments' => $attachments->count(),
            'templates'  => $templates->count(),
            'audit_events' => $auditEvents,
        ];
    }

    /**
     * Stream the documents table to NDJSON, eager-loading tag ids per chunk
     * (lazyById batches the relation load, so no N+1). Returns the row count.
     */
    private function putDocumentsNdjson(string $path): int
    {
        $handle = fopen($path, 'w');
        $count  = 0;

        try {
            Document::withTrashed()->with('tags:id')->lazyById(500)
                ->each(function (Document $d) use ($handle, &$count) {
                    $row = $d->makeVisible(['deleted_at'])->toArray();
                    // Store tag ids alongside the columns, but drop the eager-loaded
                    // relation blob — `tags` is not a documents column.
                    unset($row['tags']);
                    $row['tag_ids'] = $d->tags->pluck('id')->all();

                    fwrite($handle, $this->ndjsonLine($row));
                    $count++;
                });
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * Write an iterable of array rows as NDJSON; returns the row count. The
     * iterable is consumed lazily, so a LazyCollection keeps memory flat.
     */
    private function putNdjson(string $path, iterable $rows): int
    {
        $handle = fopen($path, 'w');
        $count  = 0;

        try {
            foreach ($rows as $row) {
                fwrite($handle, $this->ndjsonLine($row));
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function ndjsonLine(array $row): string
    {
        // One record per line — never pretty-printed, or it wouldn't stream.
        return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** Copy every referenced asset binary into the archive. */
    private function writeAssets(string $work): void
    {
        foreach (Asset::all() as $asset) {
            $disk = Storage::disk($asset->disk);
            if (! $disk->exists($asset->path)) {
                continue;
            }
            $dest = "{$work}/assets/" . basename($asset->path);
            File::put($dest, $disk->get($asset->path));
        }
    }

    /** Copy every page-attachment binary into the archive. */
    private function writeAttachments(string $work): void
    {
        foreach (Attachment::all() as $attachment) {
            $disk = Storage::disk($attachment->disk);
            if (! $disk->exists($attachment->path)) {
                continue;
            }
            $dest = "{$work}/attachment-files/" . basename($attachment->path);
            File::put($dest, $disk->get($attachment->path));
        }
    }

    // ── Readable layer (non-authoritative) ─────────────────────────────────────

    private function writeReadable(string $work): void
    {
        Document::with('workspace')->orderBy('id')->get()->each(function (Document $doc) use ($work) {
            try {
                $relDir = "{$work}/readable/" . $this->treeFolder($doc);
                File::ensureDirectoryExists($relDir);

                // The exporter writes to the local disk and returns its path; pull
                // the bytes into the archive, then drop the working copy.
                $path = (new PdfExporter())->export($doc);
                File::put("{$relDir}/{$doc->slug}.pdf", Storage::disk('local')->get($path));
                Storage::disk('local')->delete($path);
            } catch (\Throwable $e) {
                // The readable layer is best-effort; one bad page must not abort
                // the authoritative backup.
                report($e);
            }
        });
    }

    /** Folder path "<workspace>/<ancestor>/<…>" for a document, slugged + safe. */
    private function treeFolder(Document $doc): string
    {
        $parts = [$this->safe($doc->workspace?->slug ?? 'workspace')];
        foreach ($doc->ancestors() as $ancestor) {
            $parts[] = $this->safe($ancestor['slug']);
        }

        return implode('/', $parts);
    }

    // ── Manifest ───────────────────────────────────────────────────────────────

    /**
     * Encrypt the archive at rest? Only when the admin enabled it AND a key is
     * configured. If enabled but the key is missing we FAIL CLOSED — better a
     * failed backup the admin notices than a plaintext archive they think is
     * encrypted.
     */
    private function shouldEncrypt(): bool
    {
        if (! (BackupSettings::get()['encryption'] ?? false)) {
            return false;
        }

        if (! ArchiveCipher::configured()) {
            throw new \RuntimeException(
                'Backup encryption is enabled but BACKUP_ENCRYPTION_KEY is not set.',
            );
        }

        return true;
    }

    /** @param array<string,int> $counts */
    private function writeManifest(string $work, array $counts, bool $encrypt): array
    {
        // Per-file sha256 over canonical/* and assets/* for integrity verification.
        $files = [];
        foreach (File::allFiles($work) as $file) {
            $rel = str_replace($work . '/', '', $file->getPathname());
            if (str_starts_with($rel, 'readable/')) {
                continue; // readable layer is non-authoritative, not checksummed
            }
            $files[$rel] = hash_file('sha256', $file->getPathname());
        }

        $manifest = [
            'format_version' => config('backup.format_version'),
            'schema_version' => $this->schemaVersion(),
            'app'            => config('app.name'),
            // Which release wrote this archive — context for future restores
            // ("dev" for source builds).
            'app_version'    => config('app.version'),
            'created_at'     => now()->toIso8601String(),
            // Recorded in the DB `backups` row too, so RestoreService knows an
            // archive is encrypted WITHOUT first decrypting it (the manifest.json
            // inside the zip is itself encrypted).
            'encryption'     => [
                'enabled' => $encrypt,
                'cipher'  => $encrypt ? 'xchacha20poly1305-secretstream' : null,
                'fingerprint' => $encrypt ? ArchiveCipher::currentFingerprint() : null,
            ],
            'counts'         => $counts,
            'files'          => $files,
        ];

        $this->putJson("{$work}/manifest.json", $manifest);

        return $manifest;
    }

    /** The latest applied migration — our stand-in for a schema version. */
    private function schemaVersion(): ?string
    {
        return DB::table('migrations')->orderByDesc('id')->value('migration');
    }

    // ── Zip + store ────────────────────────────────────────────────────────────

    private function zip(string $work): string
    {
        $zipPath = $this->tempDir() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create backup archive.');
        }

        foreach (File::allFiles($work) as $file) {
            $zip->addFile($file->getPathname(), str_replace($work . '/', '', $file->getPathname()));
        }
        $zip->close();

        return $zipPath;
    }

    /** @return array{path:string,size:int} */
    private function storeArchive(Backup $backup, string $zipPath, bool $encrypt): array
    {
        $name        = 'backup-' . now()->format('Ymd-His') . "-{$backup->id}.zip";
        $destination = DestinationFactory::make($backup->disk);

        // The destination (local disk or SMB share) owns its own pathing; it
        // streams the archive in so an off-host target need not buffer it whole.
        if (! $encrypt) {
            return $destination->store($zipPath, $name);
        }

        // Encrypt the whole zip to a sibling .enc (streamed, bounded memory),
        // store that, then drop the intermediate. run() unlinks the plain zip.
        $encPath = $zipPath . '.enc';
        ArchiveCipher::fromConfig()->encryptFile($zipPath, $encPath);

        try {
            return $destination->store($encPath, "{$name}.enc");
        } finally {
            @unlink($encPath);
        }
    }

    /** Keep only the most-recent N successful backups on this destination. */
    private function pruneOldBackups(Backup $backup): void
    {
        $retention = (int) (BackupSettings::get()['retention'] ?? 7);
        if ($retention < 1) {
            return;
        }

        // Imported archives are exempt from rotation: someone deliberately brought
        // one in, so it's kept until manually deleted — and doesn't consume a
        // retention slot meant for scheduled/manual runs.
        //
        // Pre-restore safety snapshots rotate in their OWN window: they're
        // recovery artifacts a restore creates as a side effect, so they must
        // neither evict real backups from the retention window nor pile up
        // forever themselves.
        $this->pruneWindow($backup->disk, $retention, fn ($q) => $q->whereNotIn('trigger', ['import', 'pre-restore']));
        $this->pruneWindow($backup->disk, $retention, fn ($q) => $q->where('trigger', 'pre-restore'));
    }

    /** Delete every done backup on a destination beyond the newest $keep in the scoped set. */
    private function pruneWindow(string $disk, int $keep, callable $scope): void
    {
        $destination = DestinationFactory::make($disk);

        Backup::where('status', 'done')
            ->where('disk', $disk)
            ->tap(fn ($q) => $scope($q))
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (Backup $old) use ($destination) {
                if ($old->path) {
                    $destination->delete($old->path);
                }
                $old->delete();
            });
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function putJson(string $path, mixed $data): void
    {
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function tempDir(): string
    {
        return sys_get_temp_dir() . '/' . uniqid('wwwdoc_backup_');
    }

    private function safe(string $segment): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $segment) ?: 'item';
    }
}
