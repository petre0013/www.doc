<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Document;
use App\Models\User;
use App\Services\Backup\Destinations\DestinationFactory;
use App\Support\BackupSettings;
use App\Support\SearchVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Rebuilds the content model from a backup's CANONICAL layer. Never reads the
 * readable DOCX layer — that's lossy/derived. The archive's `content` jsonb is
 * restored verbatim, so diagrams, wiki-link marks, colours, code blocks, tags,
 * tree structure and versions round-trip losslessly.
 *
 * Destructive: it replaces the current content model wholesale, inside one
 * transaction (model events muted so the observer doesn't re-snapshot/re-render
 * during the rebuild). Runs in RestoreBackupJob on the queue.
 *
 * NOTE (scaffold): this is the happy-path rebuild. Hardening still to do —
 * pre-restore safety snapshot, schema-version migration, partial/selective
 * restore, and archive decryption.
 */
class RestoreService
{
    /** Live user ids (users aren't part of the content model, so never restored). */
    private array $validUsers = [];

    public function restore(Backup $backup): void
    {
        $work = sys_get_temp_dir() . '/' . uniqid('wwwdoc_restore_');

        try {
            $this->extract($backup, $work);
            $this->verify($work);

            // Capture the current state before the destructive wipe, so a bad
            // restore is recoverable. Aborts the restore if it can't be taken.
            $this->safetySnapshot();

            DB::transaction(function () use ($work) {
                $this->wipe();

                // Authors (created_by/updated_by/uploaded_by) reference users,
                // which aren't restored. Capture who still exists so a backup
                // taken before a user was deleted doesn't fail on a dangling FK.
                $this->validUsers = array_flip(DB::table('users')->pluck('id')->all());

                // Small tables first; tags before documents so the taggable rows
                // (written inside restoreDocuments) satisfy their FK. Groups before
                // workspaces (workspaces.group_id → workspace_groups); a pre-groups
                // archive simply has no file and inserts nothing.
                $this->insert('workspace_groups', $this->jsonArray($work, 'workspace_groups'));
                $this->insert('workspaces', $this->jsonArray($work, 'workspaces'));
                // Page folders: after workspaces (document_folders.workspace_id),
                // before documents (documents.folder_id → document_folders). A
                // pre-folders archive has no file and inserts nothing.
                $this->insert('document_folders', $this->jsonArray($work, 'document_folders'));
                $this->insert('tags', $this->jsonArray($work, 'tags'));

                // High-volume tables stream row-by-row from NDJSON (a format 1
                // archive falls back to a single .json array — see canonicalRows).
                $this->restoreDocuments($work);
                $this->insertStreamed('document_versions', $work, 'versions', ['created_by_id']);

                $this->insert('links', $this->jsonArray($work, 'links'));
                $this->insert('assets', $this->scrubUsers($this->jsonArray($work, 'assets'), ['uploaded_by_id']));
                // Attachments reference documents, so they go in after them.
                $this->insert('attachments', $this->scrubUsers($this->jsonArray($work, 'attachments'), ['uploaded_by_id']));
                // Page templates (standalone; absent from pre-1.3 archives → []).
                $this->insert('templates', $this->scrubUsers($this->jsonArray($work, 'templates'), ['created_by_id']));

                // Audit trail is append-only even across restores: never wiped,
                // archive rows are MERGED in (insert-missing by id).
                $this->mergeAuditEvents($work);

                $this->resyncSequences();
            });

            $this->restoreAssetBinaries($this->jsonArray($work, 'assets'), $work);
            $this->restoreAttachmentBinaries($this->jsonArray($work, 'attachments'), $work);
            $this->reindexSearch();
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Back up the CURRENT content model before wiping it. Canonical-only (no
     * readable PDFs — recoverability is all that matters here) via the configured
     * destination + encryption, tagged 'pre-restore'. If it can't be taken, throw
     * so the restore aborts rather than wipe with no way back.
     */
    private function safetySnapshot(): void
    {
        $snapshot = Backup::create([
            'trigger'    => 'pre-restore',
            'disk'       => BackupSettings::get()['driver'] ?? 'local',
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        try {
            app(BackupService::class)->run($snapshot, canonicalOnly: true);
        } catch (\Throwable $e) {
            $snapshot->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            throw new \RuntimeException(
                'Pre-restore safety snapshot failed; restore aborted to protect the current data. ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    // ── Extract + integrity ────────────────────────────────────────────────────

    private function extract(Backup $backup, string $work): void
    {
        // Pull the archive down from wherever it lives (local disk or SMB share)
        // to a local temp file before unzipping.
        $local = DestinationFactory::make($backup->disk)->fetch($backup->path);

        // Transparently decrypt if it's an encrypted archive. Detected by the
        // magic bytes (not the DB flag), so a hand-recovered file still works;
        // fromConfig() throws a clear error if BACKUP_ENCRYPTION_KEY is missing.
        if (ArchiveCipher::isEncrypted($local)) {
            $plain = $local . '.zip';
            ArchiveCipher::fromConfig()->decryptFile($local, $plain);
            @unlink($local);
            $local = $plain;
        }

        $zip = new ZipArchive();
        if ($zip->open($local) !== true) {
            throw new \RuntimeException('Could not open backup archive.');
        }

        // Zip-slip guard: refuse entries that could land outside $work. Our own
        // archives never contain such names, but imported ones are arbitrary
        // uploads — a `../` or absolute entry must not overwrite app files.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, '/') || str_starts_with($name, '\\')
                || str_contains($name, '..') || preg_match('/^[A-Za-z]:/', $name)) {
                $zip->close();
                throw new \RuntimeException("Archive contains an unsafe path ({$name}); refusing to extract.");
            }
        }

        $zip->extractTo($work);
        $zip->close();
        @unlink($local);
    }

    /** Re-check every canonical/asset file against the manifest's sha256. */
    private function verify(string $work): void
    {
        $manifest = json_decode(File::get("{$work}/manifest.json"), true);
        foreach ($manifest['files'] ?? [] as $rel => $expected) {
            $path = "{$work}/{$rel}";
            if (! File::exists($path) || hash_file('sha256', $path) !== $expected) {
                throw new \RuntimeException("Integrity check failed for {$rel}.");
            }
        }
    }

    // ── Rebuild ────────────────────────────────────────────────────────────────

    private function wipe(): void
    {
        // Children before parents. taggables/links/versions/attachments reference
        // documents; documents reference workspaces and document_folders;
        // document_folders and workspaces reference workspace_groups. assets/
        // templates are standalone.
        foreach (['links', 'document_versions', 'taggables', 'attachments', 'documents', 'document_folders', 'tags', 'workspaces', 'workspace_groups', 'assets', 'templates'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert(array_map(
                fn (array $row) => $this->encodeJsonColumns($row),
                $chunk,
            ));
        }
    }

    /**
     * Documents carry a self-referential parent_id, so insert parent-less first,
     * then wire up parents once all ids exist. The tag plumbing (taggables) is
     * collected on the SAME streaming pass to avoid re-reading the file, and the
     * heavy `content` jsonb is discarded each time a batch flushes — so memory
     * stays bounded to one batch plus the (tiny, all-integer) parent/tag maps.
     */
    private function restoreDocuments(string $work): void
    {
        $parents   = [];
        $taggables = [];
        $batch     = [];

        $flush = function () use (&$batch) {
            if ($batch) {
                DB::table('documents')->insert($batch);
                $batch = [];
            }
        };

        foreach ($this->canonicalRows($work, 'documents') as $doc) {
            foreach ($doc['tag_ids'] ?? [] as $tagId) {
                $taggables[] = [
                    'tag_id'        => $tagId,
                    'taggable_type' => Document::class,
                    'taggable_id'   => $doc['id'],
                ];
            }
            // Drop anything that isn't a documents column (the tag plumbing).
            unset($doc['tag_ids'], $doc['tags']);

            $doc = $this->nullMissingUsers($doc, ['created_by_id', 'updated_by_id']);

            $parents[$doc['id']] = $doc['parent_id'] ?? null;
            $doc['parent_id']    = null;

            $batch[] = $this->encodeJsonColumns($doc);
            if (count($batch) >= 200) {
                $flush();
            }
        }
        $flush();

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                DB::table('documents')->where('id', $id)->update(['parent_id' => $parentId]);
            }
        }

        foreach (array_chunk($taggables, 500) as $chunk) {
            DB::table('taggables')->insert($chunk);
        }
    }

    /**
     * Stream a high-volume table into Postgres in batches of 200. `$userCols`
     * lists author FKs to null when their user no longer exists.
     */
    private function insertStreamed(string $table, string $work, string $base, array $userCols = []): void
    {
        $batch = [];
        foreach ($this->canonicalRows($work, $base) as $row) {
            $batch[] = $this->encodeJsonColumns($this->nullMissingUsers($row, $userCols));
            if (count($batch) >= 200) {
                DB::table($table)->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table($table)->insert($batch);
        }
    }

    /**
     * Merge the archive's audit trail into the live one. The live table is NOT
     * wiped (see wipe()) — a restore must never erase audit history recorded
     * after the backup was taken. Rows are matched by id: on the same instance
     * ids are stable (the sequence only climbs), so a missing id means the row
     * was recorded before this instance's trail began (e.g. an imported archive).
     */
    private function mergeAuditEvents(string $work): void
    {
        $batch = [];

        $flush = function () use (&$batch) {
            if (! $batch) {
                return;
            }
            $existing = array_flip(
                DB::table('audit_events')->whereIn('id', array_column($batch, 'id'))->pluck('id')->all(),
            );
            $missing = array_values(array_filter($batch, fn (array $row) => ! isset($existing[$row['id']])));
            if ($missing) {
                DB::table('audit_events')->insert($missing);
            }
            $batch = [];
        };

        foreach ($this->canonicalRows($work, 'audit_events') as $row) {
            $batch[] = $this->encodeJsonColumns($this->nullMissingUsers($row, ['user_id']));
            if (count($batch) >= 200) {
                $flush();
            }
        }
        $flush();
    }

    /** Push Postgres id sequences past the highest restored id on each table. */
    private function resyncSequences(): void
    {
        foreach (['workspace_groups', 'workspaces', 'document_folders', 'documents', 'document_versions', 'tags', 'links', 'assets', 'attachments', 'templates', 'audit_events'] as $table) {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))",
                [$table],
            );
        }
    }

    private function restoreAssetBinaries(array $assets, string $work): void
    {
        foreach ($assets as $asset) {
            $src = "{$work}/assets/" . basename($asset['path']);
            if (File::exists($src)) {
                Storage::disk($asset['disk'])->put($asset['path'], File::get($src));
            }
        }
    }

    private function restoreAttachmentBinaries(array $attachments, string $work): void
    {
        foreach ($attachments as $attachment) {
            $src = "{$work}/attachment-files/" . basename($attachment['path']);
            if (File::exists($src)) {
                Storage::disk($attachment['disk'])->put($attachment['path'], File::get($src));
            }
        }
    }

    private function reindexSearch(): void
    {
        $lang = config('database.search_language', 'english');
        DB::statement('UPDATE documents SET search_vector = ' . SearchVector::expression(), [$lang, $lang]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** A small canonical table dumped as a single JSON array; [] if absent. */
    private function jsonArray(string $work, string $base): array
    {
        $path = "{$work}/canonical/{$base}.json";

        return File::exists($path) ? (json_decode(File::get($path), true) ?? []) : [];
    }

    /**
     * Yield rows for a high-volume table. Prefers the streamed NDJSON file
     * (format 2+), reading it one line at a time; falls back to a single JSON
     * array for format 1 archives.
     *
     * @return \Generator<int,array>
     */
    private function canonicalRows(string $work, string $base): \Generator
    {
        $ndjson = "{$work}/canonical/{$base}.ndjson";

        if (File::exists($ndjson)) {
            $handle = fopen($ndjson, 'r');
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line !== '') {
                        yield json_decode($line, true);
                    }
                }
            } finally {
                fclose($handle);
            }

            return;
        }

        yield from $this->jsonArray($work, $base); // format 1 fallback
    }

    /** Null author FKs pointing at users that no longer exist, across a list of rows. */
    private function scrubUsers(array $rows, array $cols): array
    {
        return array_map(fn (array $row) => $this->nullMissingUsers($row, $cols), $rows);
    }

    /** Same, for a single row — keeps the FK valid so restore doesn't fail on a deleted author. */
    private function nullMissingUsers(array $row, array $cols): array
    {
        foreach ($cols as $col) {
            if (isset($row[$col]) && ! isset($this->validUsers[$row[$col]])) {
                $row[$col] = null;
            }
        }

        return $row;
    }

    /** json_encode array-valued columns (content/metadata/tags jsonb) for raw insert. */
    private function encodeJsonColumns(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = json_encode($value);
            }
        }

        return $row;
    }
}
