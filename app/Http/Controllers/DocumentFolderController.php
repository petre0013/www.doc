<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentFolderRequest;
use App\Http\Requests\UpdateDocumentFolderRequest;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;

class DocumentFolderController extends Controller
{
    public function store(StoreDocumentFolderRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('create', DocumentFolder::class);

        // workspace_id comes from the route, never the payload. Position lands the
        // folder at the TOP of the shared top-level order (folders + loose root
        // pages), not below its same-position siblings — see topOfOrder().
        $folder = $workspace->folders()->create([
            ...$request->validated(),
            'position' => $this->topOfOrder($workspace),
        ]);

        Audit::record('folder.created', $folder, [
            'name'      => $folder->name,
            'workspace' => $workspace->name,
        ]);

        return back();
    }

    /** One below the lowest top-level position (folders + loose root pages), or 0 when empty. */
    private function topOfOrder(Workspace $workspace): int
    {
        $mins = array_filter([
            $workspace->folders()->min('position'),
            $workspace->documents()->whereNull('parent_id')->whereNull('folder_id')->min('position'),
        ], fn ($p) => $p !== null);

        return $mins ? min($mins) - 1 : 0;
    }

    public function update(UpdateDocumentFolderRequest $request, DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);

        $from = $folder->name;
        $folder->update($request->validated());

        if ($folder->wasChanged('name')) {
            Audit::record('folder.renamed', $folder, ['from' => $from, 'to' => $folder->name]);
        }

        return back();
    }

    public function destroy(DocumentFolder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);

        $name = $folder->name;

        // Un-file the pages instead of trashing them — a folder is a label, not
        // an owner of content. withoutTimestamps keeps each page's updated_at
        // frozen: being un-filed is structural, not an edit (an Eloquent relation
        // update auto-injects updated_at otherwise — the same trap the group
        // delete path hit). The DB's ON DELETE SET NULL would also catch this,
        // but doing it explicitly keeps the behavior in the app where it's
        // testable and auditable rather than relying on a backstop.
        Document::withoutTimestamps(fn () => $folder->documents()->update(['folder_id' => null]));

        $folder->delete();

        // Subject is gone (hard delete) — identity rides in context so the morph
        // doesn't dangle, per audit rules.
        Audit::record('folder.deleted', null, ['folder_id' => $folder->id, 'name' => $name]);

        return back();
    }
}
