<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    /**
     * List trashed workspaces and documents. Only "top-level" trashed items are
     * shown: a page whose parent or workspace is also trashed is part of a larger
     * trashed unit and is restored together with it, so it isn't listed alone.
     */
    public function index(): Response
    {
        \Illuminate\Support\Facades\Gate::authorize('viewTrash');

        $workspaces = Workspace::onlyTrashed()
            ->withCount(['documents' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Workspace $w) => [
                'id'          => $w->id,
                'name'        => $w->name,
                'deleted_at'  => $w->deleted_at?->toIso8601String(),
                'page_count'  => $w->documents_count,
            ]);

        $documents = Document::onlyTrashed()
            ->with(['workspace:id,name'])
            ->whereHas('workspace') // exclude pages trashed via their workspace
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent'))
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Document $d) => [
                'id'          => $d->id,
                'title'       => $d->title,
                'workspace'   => $d->workspace ? ['id' => $d->workspace->id, 'name' => $d->workspace->name] : null,
                'deleted_at'  => $d->deleted_at?->toIso8601String(),
                'child_count' => $d->children()->onlyTrashed()->count(),
            ]);

        return Inertia::render('Trash/Index', [
            'workspaces' => $workspaces,
            'documents'  => $documents,
        ]);
    }

    /**
     * Permanently delete everything in the trash. Workspaces are purged with their
     * documents; any remaining individually-trashed pages are then force-deleted
     * (their subtrees included). Irreversible.
     */
    public function empty(): RedirectResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('emptyTrash');

        $workspaceCount = Workspace::onlyTrashed()->count();
        $documentCount  = Document::onlyTrashed()->count();

        Workspace::onlyTrashed()->get()->each->forceDeleteWithDocuments();

        Document::onlyTrashed()->get()->each(function (Document $doc) {
            // The model may already be gone if an ancestor was force-deleted above.
            if (Document::onlyTrashed()->whereKey($doc->getKey())->exists()) {
                $doc->forceDeleteSubtree();
            }
        });

        Audit::record('trash.emptied', null, [
            'workspaces' => $workspaceCount,
            'documents'  => $documentCount,
        ]);

        return back()->with('success', 'Trash emptied.');
    }

    public function restoreDocument(int $document): RedirectResponse
    {
        $doc = Document::onlyTrashed()->findOrFail($document);
        $this->authorize('restore', $doc);

        $doc->restoreSubtree();

        Audit::record('document.restored', $doc, ['title' => $doc->title]);

        return back()->with('success', "Restored \"{$doc->title}\".");
    }

    public function forceDeleteDocument(int $document): RedirectResponse
    {
        $doc = Document::onlyTrashed()->findOrFail($document);
        $this->authorize('forceDelete', $doc);

        $title = $doc->title;
        $workspaceId = $doc->workspace_id;
        $doc->forceDeleteSubtree();

        // Subject is gone — keep its identity in the context snapshot.
        Audit::record('document.purged', null, [
            'title'        => $title,
            'document_id'  => $doc->getKey(),
            'workspace_id' => $workspaceId,
        ]);

        return back()->with('success', "Permanently deleted \"{$title}\".");
    }

    public function restoreWorkspace(int $workspace): RedirectResponse
    {
        $ws = Workspace::onlyTrashed()->findOrFail($workspace);
        $this->authorize('restore', $ws);

        $ws->restoreWithDocuments();

        Audit::record('workspace.restored', $ws, ['name' => $ws->name]);

        return back()->with('success', "Restored \"{$ws->name}\".");
    }

    public function forceDeleteWorkspace(int $workspace): RedirectResponse
    {
        $ws = Workspace::onlyTrashed()->findOrFail($workspace);
        $this->authorize('forceDelete', $ws);

        $name = $ws->name;
        $ws->forceDeleteWithDocuments();

        Audit::record('workspace.purged', null, [
            'name'         => $name,
            'workspace_id' => $ws->getKey(),
        ]);

        return back()->with('success', "Permanently deleted \"{$name}\".");
    }
}
