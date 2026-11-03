<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class VersionController extends Controller
{
    /** List all versions for a document. */
    public function index(Document $document): Response
    {
        $this->authorize('view', $document);

        $versions = $document->versions()
            ->with('creator:id,name')
            ->select(['id', 'document_id', 'title', 'summary', 'created_by_id', 'created_at'])
            ->get();

        return Inertia::render('Documents/Versions/Index', [
            'document' => $document->only('id', 'title', 'workspace_id'),
            'workspace' => $document->workspace->only('id', 'name'),
            'versions'  => $versions,
        ]);
    }

    /** Show a single historical version (read-only). */
    public function show(Document $document, DocumentVersion $version): Response
    {
        $this->authorize('view', $document);
        abort_if($version->document_id !== $document->id, 404);

        return Inertia::render('Documents/Versions/Show', [
            'document' => $document->only('id', 'title', 'workspace_id'),
            'workspace' => $document->workspace->only('id', 'name'),
            'version'   => $version->load('creator:id,name'),
        ]);
    }

    /**
     * Restore a version by creating a new document save with the old content.
     * Never overwrites history — adds a new snapshot instead.
     */
    public function restore(Document $document, DocumentVersion $version): RedirectResponse
    {
        $this->authorize('update', $document);
        abort_if($version->document_id !== $document->id, 404);

        // Full revert: restore the tag set first (matched by name, recreated if
        // since deleted) so it's in place before the save below snapshots a new
        // version — keeping that fresh snapshot consistent with the restore.
        $tagIds = collect($version->tags ?? [])
            ->map(fn ($name) => Tag::firstOrCreate(['name' => $name])->id)
            ->all();
        $document->tags()->sync($tagIds);

        $document->title   = $version->title;
        $document->content = $version->content;
        $document->save();

        // The save above also logs document.updated; this event carries the
        // intent (which snapshot was restored) for the audit trail.
        \App\Support\Audit::record('document.version_restored', $document, [
            'title'        => $document->title,
            'version_id'   => $version->id,
            'version_date' => $version->created_at->toIso8601String(),
        ]);

        return redirect()->route('documents.show', $document)
            ->with('success', "Restored to version from {$version->created_at->diffForHumans()}.");
    }
}
