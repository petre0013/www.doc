<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\Link;
use App\Services\RenderDocument;
use App\Support\Audit;
use App\Support\SearchVector;
use App\Support\TipTap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentObserver
{
    /** Stamp authorship before the row is written. */
    public function saving(Document $document): void
    {
        // Keep stored content valid for ProseMirror (no empty text nodes) on
        // every write path — controllers, seeders, import jobs. Only touch it
        // when it actually changed so tree ops (move/reorder) stay no-ops.
        if ($document->isDirty('content') && is_array($document->content)) {
            $document->content = TipTap::normalize($document->content);
        }

        if ($document->isDirty('content')) {
            $document->content_html = RenderDocument::toHtml($document->content ?? []);
        }

        $userId = Auth::id();

        if (! $document->exists && $userId && ! $document->created_by_id) {
            $document->created_by_id = $userId;
        }

        // Only update editor attribution when content or title actually changes —
        // position/parent changes (drag-reorder, move) must not shift the timestamp.
        if ($userId && $document->isDirty(['content', 'title'])) {
            $document->updated_by_id = $userId;
        }

        // Bump the optimistic-locking counter on the same condition (content/title
        // edits only, never structural moves). This is the token the editor checks
        // its `base_version` against, so every real edit invalidates stale bases.
        if ($document->isDirty(['content', 'title'])) {
            $document->version = ($document->version ?? 0) + 1;
        }
    }

    public function saved(Document $document): void
    {
        // Only snapshot/re-link when content or title actually changed —
        // tree ops (move, reorder) touch position only.
        $contentChanged = $document->wasRecentlyCreated
            || $document->wasChanged('content')
            || $document->wasChanged('title');

        if (! $contentChanged) {
            return;
        }

        // Bubble the activity timestamp up to the workspace.
        $document->workspace?->touch();

        $html = (string) $document->content_html;

        $this->syncLinks($document);
        $this->snapshotVersion($document, $html);
        $this->updateSearchVector($document);

        // Observer-level so every edit path is covered (controllers, imports,
        // restores). Structural moves bailed out above, so they never land here —
        // they're audited as document.moved by their controllers instead.
        //
        // The create flow saves twice on one instance (bare row, then content) —
        // that's ONE user action, so the second save is not audited again.
        if ($document->wasRecentlyCreated && $document->creationAudited) {
            return;
        }

        // An import's placeholder row is not a page yet: its event is deferred
        // to the job that fills it in (below), so a failed import audits nothing
        // and a successful one logs the real title, not "Importing …".
        if ($document->importPlaceholder) {
            return;
        }

        $isCreation = $document->wasRecentlyCreated || $document->importCompleted;

        Audit::record(
            $isCreation ? 'document.created' : 'document.updated',
            $document,
            array_filter([
                'title'    => $document->title,
                'template' => $isCreation ? $document->sourceTemplateName : null,
                'import'   => $isCreation ? $document->sourceImportName : null,
                'folder'   => $isCreation ? $document->sourceFolderName : null,
            ]),
            Auth::id() ?? $document->updated_by_id ?? $document->created_by_id,
            $document->auditIp,
        );

        $document->creationAudited = $document->wasRecentlyCreated;
    }

    /**
     * A newly created page resolves any previously-broken inbound wiki-links to
     * its title. A broken link means NO title match existed at its source's sync
     * time, so this page is now the unique match — exactly what a source re-sync
     * would produce. Only null targets are touched, so already-resolved links are
     * left alone. Links aren't audited and the source doc isn't saved, so no
     * updated_at/version bump on sources.
     */
    public function created(Document $document): void
    {
        Link::whereNull('target_document_id')
            ->where('target_title', $document->title)
            ->update(['target_document_id' => $document->getKey()]);
    }

    public function deleted(Document $document): void
    {
        $document->workspace?->touch();
    }

    /**
     * Permanently purging a page: delete its attachment binaries. The DB foreign
     * key cascade removes the attachment ROWS, but bypasses Eloquent events, so the
     * files would otherwise be orphaned on disk. Fires on every force-delete path
     * (trash purge of a page subtree AND a workspace purge), unlike a soft delete,
     * which leaves the page restorable from Trash with its files intact.
     */
    public function forceDeleting(Document $document): void
    {
        foreach ($document->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    /** Snapshot every save into the version history (never destructive). */
    protected function snapshotVersion(Document $document, string $html): void
    {
        // Skip the blank state a page is in the instant it's created, before any
        // content is written — otherwise the oldest "version" is always empty.
        if ($document->wasRecentlyCreated && TipTap::isEmpty($document->content)) {
            return;
        }

        // Capture the tag set so a restore is a full revert, not content-only.
        // Read fresh (controllers sync tags *before* the snapshotting save) and
        // store names — they outlive tag id churn and rename/delete.
        $tags = $document->tags()->orderBy('name')->pluck('name')->all();

        // Snapshot attachments so we have a historical record of what was attached.
        $attachments = $document->attachments()
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'original_name', 'size', 'mime', 'checksum'])
            ->toArray();

        // The previous snapshot is the baseline for this version's change
        // summary (fetched BEFORE creating the new row). First version → null.
        $previous = $document->versions()->latest('id')->first();

        $document->versions()->create([
            'title'         => $document->title,
            'content'       => $document->content ?? [],
            'content_html'  => $html,
            'tags'          => $tags,
            'attachments'   => $attachments,
            'summary'       => $previous ? \App\Support\DocumentDiff::summarize(
                ['title' => $previous->title, 'content' => $previous->content, 'tags' => $previous->tags ?? []],
                ['title' => $document->title, 'content' => $document->content ?? [], 'tags' => $tags],
            ) : null,
            'created_by_id' => Auth::id() ?? $document->updated_by_id,
        ]);
    }



    /**
     * Maintain the Postgres tsvector column for full-text search.
     * Title gets weight A, content_html (tags stripped) weight B — so title
     * matches rank higher. Shares its SQL with `search:reindex` via SearchVector
     * so the live and bulk paths index identical text (incl. diagram labels).
     */
    protected function updateSearchVector(Document $document): void
    {
        $lang = config('database.search_language', 'english');

        DB::statement(
            'UPDATE documents SET search_vector = ' . SearchVector::expression() . ' WHERE id = ?',
            [$lang, $lang, $document->getKey()]
        );
    }

    /**
     * Rebuild this document's outgoing links from its [[Wiki-link]] references.
     * Stores a short context snippet alongside each link for the backlinks panel.
     */
    protected function syncLinks(Document $document): void
    {
        $targets = TipTap::wikiLinkTargets($document->content);

        $document->outgoingLinks()->delete();

        // Resolve every target with two queries total instead of one per link:
        // one keyed lookup for links that carry an explicit target id, and one
        // title lookup for typed links, both batched via whereIn.
        $byId = Document::query()
            ->whereKey(array_filter(array_column($targets, 'target_id')))
            ->get()
            ->keyBy('id');

        $typedTitles = array_values(array_unique(array_map(
            fn ($t) => $t['title'],
            array_filter($targets, fn ($t) => ! $t['target_id']),
        )));

        // Best typed-title match per title: same-workspace pages first, then the
        // oldest (original) page — mirrors the old per-link ordering, resolved in
        // PHP over one query rather than a query per link.
        $byTitle = Document::query()
            ->whereIn('title', $typedTitles)
            ->whereKeyNot($document->getKey())
            ->get(['id', 'title', 'workspace_id', 'created_at'])
            ->sort(fn ($a, $b) => [$b->workspace_id === $document->workspace_id, $a->created_at->getTimestamp(), $a->id]
                <=> [$a->workspace_id === $document->workspace_id, $b->created_at->getTimestamp(), $b->id])
            ->groupBy('title')
            ->map(fn ($group) => $group->first());

        foreach ($targets as $targetData) {
            $title = $targetData['title'];
            $target = $targetData['target_id']
                ? $byId->get($targetData['target_id'])
                : $byTitle->get($title);

            Link::create([
                'source_document_id' => $document->getKey(),
                'target_document_id' => $target?->getKey(),
                'target_title'       => $title,
                'context'            => TipTap::contextAround($document->content, $title),
            ]);
        }
    }
}
