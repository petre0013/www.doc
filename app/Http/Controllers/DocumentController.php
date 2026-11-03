<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Tag;
use App\Models\Workspace;
use App\Support\BulkReorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function exportDiagram(Request $request)
    {
        // Server-side render of a caller-supplied graph — viewer-level access,
        // matching the app's convention that every action authorizes.
        $this->authorize('viewAny', Document::class);

        $validated = $request->validate([
            'graph' => 'required|array',
            'name'  => 'nullable|string',
        ]);

        $rendered = \App\Support\DiagramSvg::render($validated['graph']);
        $svg = $rendered['svg'];
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($validated['name'] ?? 'network-diagram')));
        $slug = trim($slug, '-');
        if (!$slug) {
            $slug = 'network-diagram';
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml;charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $slug . '.svg"',
        ]);
    }

    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        $this->recordView($document);

        $document->load([
            'workspace',
            'tags',
            'creator',
            'updater',
            'attachments.uploader:id,name',
            'outgoingLinks.target:id,title,slug',
        ]);

        // Pages that link here (backlinks). One entry per referencing page —
        // a page that links several times shows once, with its first snippet.
        // Soft-deleted sources drop out via the relation's default scope.
        $backlinks = $document->backlinks()
            ->with('source:id,title,slug')
            ->get()
            ->filter(fn ($link) => $link->source !== null)
            ->unique('source_document_id')
            ->map(fn ($link) => [
                'id'      => $link->source->id,
                'title'   => $link->source->title,
                'context' => $link->context,
            ])
            ->values();

        return Inertia::render('Documents/Show', [
            'document'     => $document,
            'isStarred'    => DB::table('document_user')
                ->where('user_id', Auth::id())
                ->where('document_id', $document->id)
                ->whereNotNull('starred_at')
                ->exists(),
            'versionsCount' => $document->versions()->count(),
            // Direct children for the "Contents" folder view. children_count lets
            // the list mark a child that is itself a folder. Ordered by position.
            'children'     => $document->children()
                ->withCount('children')
                ->get(['id', 'title', 'slug', 'parent_id', 'position', 'updated_at']),
            'breadcrumbs'  => $document->ancestors(),
            'backlinks'    => $backlinks,
            'allTags'      => Tag::orderBy('name')->get(),
            'allDocuments' => Document::with('workspace:id,name')->orderBy('title')->get(['id', 'title', 'slug', 'workspace_id']),
            'workspaces'   => Workspace::orderBy('name')->get(['id', 'name']),
            // This workspace's folders, so "New page" here offers the same
            // destinations as the workspace tree's dialog does.
            'folders'      => DocumentFolder::where('workspace_id', $document->workspace_id)
                ->orderBy('position')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Toggle the current user's star on a page. Personal navigation state, so
     * any role that can VIEW the page may star it, and — like the view stamp
     * below — it is deliberately not audited (see .claude/rules/audit.md).
     */
    public function star(Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $starred = DB::table('document_user')
            ->where('user_id', Auth::id())
            ->where('document_id', $document->id)
            ->value('starred_at');

        $affected = DB::table('document_user')
            ->where('user_id', Auth::id())
            ->where('document_id', $document->id)
            ->update(['starred_at' => $starred ? null : now()]);

        if (! $affected) {
            // No pivot row yet; insertOrIgnore so a concurrent first write
            // can't trip the unique(user, document) constraint.
            DB::table('document_user')->insertOrIgnore([
                'user_id'     => Auth::id(),
                'document_id' => $document->id,
                'starred_at'  => now(),
            ]);
        }

        return back();
    }

    /**
     * Stamp the current user's "recently viewed" timestamp for a page.
     * Query-builder only — an Eloquent save on the shared document row would
     * bump updated_at and contend with the optimistic-lock counter on every
     * page VIEW. Throttled: a stamp fresher than 5 minutes is left alone so
     * ordinary navigation doesn't turn every view into a write.
     */
    protected function recordView(Document $document): void
    {
        $updated = DB::table('document_user')
            ->where('user_id', Auth::id())
            ->where('document_id', $document->id)
            ->where(fn ($q) => $q->whereNull('last_viewed_at')
                ->orWhere('last_viewed_at', '<', now()->subMinutes(5)))
            ->update(['last_viewed_at' => now()]);

        if (! $updated && ! DB::table('document_user')
                ->where('user_id', Auth::id())
                ->where('document_id', $document->id)
                ->exists()) {
            DB::table('document_user')->insertOrIgnore([
                'user_id'        => Auth::id(),
                'document_id'    => $document->id,
                'last_viewed_at' => now(),
            ]);
        }
    }

    public function preview(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $plain = \App\Support\TipTap::plainText($document->content);

        return response()->json([
            'id'      => $document->id,
            'title'   => $document->title,
            'excerpt' => mb_strimwidth(trim($plain), 0, 220, '…'),
        ]);
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $validated = $request->validated();

        $template = isset($validated['template_id'])
            ? \App\Models\Template::findOrFail($validated['template_id'])
            : null;

        // Same invariant refile() enforces: folder membership is a TOP-LEVEL
        // notion, so a page cannot be both a subpage and a folder member. The DB
        // enforces it too — checking here makes it a 422 instead of a 500.
        if (($validated['folder_id'] ?? null) !== null && ($validated['parent_id'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'folder_id' => 'Only a top-level page can be filed in a folder.',
            ]);
        }

        // Create the bare page first (a blank page snapshots no version), attach
        // tags, THEN write content — so the first version's snapshot captures the
        // tags too. The version observer reads tags off the page at save time.
        $document = new Document(array_diff_key($validated, ['tags' => '', 'content' => '', 'template_id' => '']));

        if (! isset($validated['position'])) {
            $document->position = Document::topPosition(
                $document->workspace_id,
                $document->parent_id,
                $document->folder_id,
            );
        }

        $document->sourceTemplateName = $template?->name; // surfaces in the document.created audit context
        // Where it was filed, for the same context. Creating INTO a folder is one
        // action — it logs document.created carrying the folder, not a second
        // document.moved (rule 7: one event per user action).
        $document->sourceFolderName = $document->folder_id
            ? DocumentFolder::find($document->folder_id)?->name
            : null;
        $document->save();

        if ($request->has('tags')) {
            $document->tags()->sync($request->input('tags'));
        }

        // A template supplies the starting content when the request carries none.
        // Copied verbatim (no token substitution) — the observer then parses
        // wiki-links and snapshots v1 exactly as for hand-written content.
        $content = $validated['content'] ?? $template?->content;
        if ($content !== null) {
            $document->update(['content' => $content]);
        }

        return redirect()->to(route('documents.show', $document) . '?edit=1');
    }

    public function update(UpdateDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $validated = $request->validated();

        // Optimistic locking: reject a content/title save whose base is stale — the
        // page was edited by someone else since this editor loaded it. The client
        // then resolves the conflict (reload theirs / overwrite with mine, which
        // re-submits with force). Structural/metadata- or tags-only saves carry no
        // base_version and skip this. Bail BEFORE mutating anything (incl. tags).
        $editingContent = array_key_exists('content', $validated) || array_key_exists('title', $validated);
        $baseVersion = $validated['base_version'] ?? null;

        if ($editingContent && $baseVersion !== null && ! ($validated['force'] ?? false)
            && (int) $baseVersion !== (int) $document->version) {
            $document->loadMissing('updater');

            return back()->with('saveConflict', [
                'title'      => $document->title,
                'content'    => $document->content,
                'version'    => $document->version,
                'updated_at' => $document->updated_at,
                'updated_by' => $document->updater?->name,
            ]);
        }

        // Sync tags BEFORE the content/title save: the version snapshot the observer
        // takes on that save reads the page's current tags, so a full-revert restore
        // needs the new set already in place.
        $tagsChanged = false;
        $oldTags = [];
        if ($request->has('tags')) {
            $oldTags = $document->tags()->orderBy('name')->pluck('name')->all();
            $changes = $document->tags()->sync($request->input('tags'));
            $tagsChanged = (bool) array_filter($changes);
        }

        $document->update(array_diff_key($validated, ['tags' => '', 'base_version' => '', 'force' => '']));

        // When only tags changed, no version was snapshotted and the observer's
        // workspace touch didn't run — touch explicitly so listings refresh.
        // The observer's document.updated didn't fire either (no content/title
        // change), so record the action here; a combined save stays ONE event.
        if ($tagsChanged && ! $document->wasChanged(['content', 'title'])) {
            $document->workspace?->touch();

            \App\Support\Audit::record('document.tags_changed', $document, [
                'title' => $document->title,
                'from'  => implode(', ', $oldTags) ?: null,
                'to'    => implode(', ', $document->tags()->orderBy('name')->pluck('name')->all()) ?: null,
            ]);
        }

        return redirect()->route('documents.show', $document);
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $workspace = $document->workspace;
        $document->trashSubtree();

        // One event per user action — not one per cascaded subtree page.
        \App\Support\Audit::record('document.trashed', $document, ['title' => $document->title]);

        return redirect()->route('workspaces.show', $workspace)
            ->with('success', "Moved \"{$document->title}\" to trash.");
    }

    /**
     * Rename a page in place (title only), staying on the current page — the
     * inline-title edit from the workspace tree's Edit mode. Unlike update(), which
     * redirects to the document editor, this returns back() so the tree stays put.
     * A title change IS a content edit: the observer bumps version, snapshots, and
     * records document.updated, exactly as an editor save would.
     */
    public function rename(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $document->update(['title' => $data['title']]);

        return back();
    }

    /** Immediate children of a document, in display order. */
    public function children(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(
            $document->children()->get(['id', 'title', 'slug', 'parent_id', 'position'])
        );
    }

    /**
     * File a root page into a folder, or back out to loose (folder_id = null).
     *
     * Deliberately separate from move(): this never touches parent_id, and it is
     * the ONLY way to change folder membership — refiling is a menu action, not a
     * drag (mirroring "Move to group"). Structural, so no updated_at/version bump.
     */
    public function refile(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'folder_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('document_folders', 'id'),
            ],
            'position' => ['nullable', 'integer'],
        ]);

        $folderId = $data['folder_id'] ?? null;

        // Both invariants are DB-enforced, so violating them would raise a
        // QueryException (a 500). Check here so a bad request is a 422 the client
        // can render instead.
        if ($folderId !== null) {
            if ($document->parent_id !== null) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Only a top-level page can be filed in a folder.',
                ]);
            }

            $folder = DocumentFolder::find($folderId);
            if (! $folder || $folder->workspace_id !== $document->workspace_id) {
                throw ValidationException::withMessages([
                    'folder_id' => 'The folder must belong to this page\'s workspace.',
                ]);
            }
        }

        $folderChanged = $folderId !== $document->folder_id;
        $fromFolder = $document->folder?->name;

        $document->folder_id = $folderId;
        $document->position = $data['position'] ?? $document->position;

        // Structural move must not shift updated_at — nor `version`, which is the
        // editor's optimistic lock: bumping it would fake an edit conflict for
        // anyone with the page open. DocumentObserver only bumps version on
        // content/title changes, so this save leaves it alone.
        Document::withoutTimestamps(fn () => $document->save());

        // Refiling is audited (parity with workspace.moved); a position-only
        // change within the same folder is layout noise and is not.
        if ($folderChanged) {
            \App\Support\Audit::record('document.moved', $document, [
                'title'       => $document->title,
                'from_folder' => $fromFolder,
                'to_folder'   => $folderId ? DocumentFolder::find($folderId)?->name : null,
            ]);
        }

        return back();
    }

    /** Move a node to a new parent (and optionally a new workspace). */
    public function move(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            // whereNull: `exists` alone counts soft-deleted rows, so a trashed
            // parent would pass here and then Document::find() below (trashed
            // excluded) would silently skip the workspace + cycle checks —
            // leaving the page a live child of a trashed parent, invisible in
            // every tree view.
            'parent_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('documents', 'id')->whereNull('deleted_at'),
            ],
            // Same soft-delete trap as parent_id: a trashed destination would
            // pass a plain `exists` and swallow the subtree invisibly.
            'workspace_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('workspaces', 'id')->whereNull('deleted_at'),
            ],
            'position' => ['nullable', 'integer'],
            // Optional: the destination parent's full child order (page ids) so a
            // re-parent and the sibling reordering happen in one atomic request.
            'order' => ['nullable', 'array'],
            'order.*' => ['integer', 'exists:documents,id'],
        ]);

        $parentId = $data['parent_id'] ?? null;
        $newWorkspaceId = $data['workspace_id'] ?? $document->workspace_id;

        if ($parentId !== null) {
            // Fail rather than skip if the parent vanished between validation
            // and here — proceeding would bypass the workspace/cycle checks.
            $parent = Document::find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The parent document no longer exists.',
                ]);
            }
            if ($parent->workspace_id !== (int) $newWorkspaceId) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The parent document must belong to the destination workspace.',
                ]);
            }

            if ($this->wouldCycle($document, (int) $parentId)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A document cannot be moved inside itself or one of its descendants.',
                ]);
            }
        }
        $workspaceChanged = $newWorkspaceId !== $document->workspace_id;
        $parentChanged = $parentId !== $document->parent_id;
        $movedFrom = ['workspace_id' => $document->workspace_id, 'parent_id' => $document->parent_id];

        $document->parent_id = $parentId;
        $document->workspace_id = $newWorkspaceId;
        $document->position = $data['position'] ?? $document->position;

        // A page that stops being top-level, or leaves the workspace its folder
        // lives in, cannot keep that folder: folder_id is root-only and
        // same-workspace, both enforced in the schema. Clear it rather than let
        // the write blow up. The folder membership is a casualty of the move the
        // user asked for, so it rides that document.moved event rather than
        // emitting a second one.
        if ($parentId !== null || $workspaceChanged) {
            $document->folder_id = null;
        }

        // A move carries the slug over unchanged; re-slug if it now collides with
        // a page already in the destination workspace (slugs are unique per ws).
        if ($workspaceChanged) {
            $document->reslugForWorkspace();
        }

        // Structural moves must not shift updated_at — only content edits should.
        Document::withoutTimestamps(fn () => $document->save());

        // A cross-workspace move must carry the whole subtree along.
        if ($workspaceChanged) {
            $document->moveSubtreeToWorkspace($newWorkspaceId);
        }

        // Re-parenting and cross-workspace moves are audited; pure sibling
        // reordering (position only) is layout noise and is not.
        if ($workspaceChanged || $parentChanged) {
            \App\Support\Audit::record('document.moved', $document, [
                'title' => $document->title,
                'from'  => $movedFrom,
                'to'    => ['workspace_id' => (int) $newWorkspaceId, 'parent_id' => $parentId],
            ]);
        }

        // Normalise the destination siblings' positions to the given order.
        // Constrain each update to an actual child of the new parent so a client
        // can't reposition unrelated pages by smuggling their ids into `order`.
        foreach ($data['order'] ?? [] as $position => $id) {
            Document::withoutTimestamps(fn () => Document::whereKey($id)
                ->where('parent_id', $parentId)
                ->update(['position' => $position]));
        }

        // A cross-workspace move gets a confirmation like trash/restore do;
        // re-parents and sibling reorders stay silent (layout, not a "result").
        // Nullsafe: the destination could be trashed between validation and here.
        if ($workspaceChanged && ($destination = Workspace::find((int) $newWorkspaceId))) {
            return back()->with('success', "Moved \"{$document->title}\" to \"{$destination->name}\".");
        }

        return back();
    }

    /**
     * Persist a whole workspace's page tree in one shot — parent + position for
     * every node. The "Reorder" mode batches all drags locally and saves once on
     * "Done", instead of a request per drop, so the tree lands in one atomic write.
     */
    public function restructure(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'nodes'             => ['required', 'array'],
            'nodes.*.id'        => ['required', 'integer', 'distinct'],
            'nodes.*.parent_id' => ['nullable', 'integer'],
            'nodes.*.position'  => ['required', 'integer'],
        ]);

        $nodes = $data['nodes'];
        $ids   = array_column($nodes, 'id');

        // Every node must be a live page in THIS workspace — no smuggling ids
        // from another workspace into the batch.
        if ($workspace->documents()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['nodes' => 'Every page must belong to this workspace.']);
        }

        // Each parent must be inside the batch (or null), and the result acyclic —
        // a hostile client could otherwise send a cycle that hangs tree walks.
        $idSet    = array_flip($ids);
        $parentOf = [];
        foreach ($nodes as $node) {
            $parentId = $node['parent_id'] ?? null;
            if ($parentId !== null && ! isset($idSet[$parentId])) {
                throw ValidationException::withMessages(['nodes' => 'A parent must be one of the reordered pages.']);
            }
            $parentOf[$node['id']] = $parentId;
        }
        foreach ($parentOf as $id => $parentId) {
            $seen = [];
            while ($parentId !== null) {
                if ($parentId === $id || isset($seen[$parentId])) {
                    throw ValidationException::withMessages(['nodes' => 'A page cannot be nested inside itself.']);
                }
                $seen[$parentId] = true;
                $parentId = $parentOf[$parentId] ?? null;
            }
        }

        // Structural change only — one statement, and don't bump updated_at.
        BulkReorder::tree('documents', $nodes);

        \App\Support\Audit::record('workspace.restructured', $workspace, [
            'name'       => $workspace->name,
            'page_count' => count($nodes),
        ]);

        return back();
    }

    /**
     * Reorder the interleaved top level of a workspace AND refile pages between
     * folders, in one atomic structural save. Folders and loose root pages share
     * ONE position sequence (`items`, so a loose page can sit between two folders);
     * each folder's members ride the `folders` companion axis, tagged with the
     * folder they now belong to — so a drag that carries a page from one folder
     * into another (or out to loose) lands its new `folder_id` in the same "Done".
     * The exact mirror of WorkspaceController@reorderTopLevel (folders play the
     * part of groups, loose pages the part of ungrouped workspaces).
     *
     * A page's target is derived from WHERE it appears: a `items` page becomes a
     * loose root page (folder_id = null); a `folders[].members` page joins that
     * folder as a root page; a `subtrees` page is nested under another page. Both
     * sides are made consistent in the write — a top-level page has its parent_id
     * cleared (un-nested), a subtree page has its folder_id cleared — so root-only
     * (folder_id IS NULL OR parent_id IS NULL) always holds. Positions are
     * structural sort keys; the raw writes never touch updated_at or the
     * optimistic-lock `version`.
     *
     * Reordering is layout noise and stays unaudited. A page whose folder_id
     * actually changes records ONE `document.moved` (parity with the standalone
     * refile); re-nesting OR un-nesting a page to the top level records
     * `workspace.restructured`, like the dedicated restructure path.
     */
    public function reorderTopLevel(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            // `present`, not `required`: a save that only re-nests pages carries an
            // empty top level, which `required` would reject as missing.
            'items'        => ['present', 'array'],
            'items.*.type' => ['required', 'string', 'in:folder,page'],
            'items.*.id'   => ['required', 'integer'],
            // Each folder with its ordered members. Replaces the old flat `members`
            // list: the folder id is what lets a member be REFILED here, not just
            // reordered.
            'folders'             => ['array'],
            'folders.*.id'        => ['required', 'integer'],
            'folders.*.members'   => ['present', 'array'],
            'folders.*.members.*' => ['integer'],
            // Optional page nesting (subpages, parent_id != null). A unified drag
            // can re-nest pages in the same gesture as a top-level reorder, so it
            // rides the same atomic save.
            'subtrees'             => ['array'],
            'subtrees.*.id'        => ['required', 'integer', 'distinct'],
            'subtrees.*.parent_id' => ['required', 'integer'],
            'subtrees.*.position'  => ['required', 'integer'],
            // Folders CREATED in the Edit session — deferred here so Cancel can
            // discard them (nothing hit the server) and Done persists them in the
            // same atomic save. Each carries a client temp id (negative, so it can
            // never collide with a real folder id) that `items`/`folders` reference;
            // the write maps it to the real id.
            'newFolders'        => ['array'],
            'newFolders.*.id'   => ['required', 'integer', 'lt:0', 'distinct'],
            'newFolders.*.name' => ['required', 'string', 'max:255'],
        ]);

        // Folder slots (position = index in the interleaved top level) + the loose
        // pages that share that same index space.
        $folderPairs = [];
        $looseRows   = []; // [id, folder_id (null), position]
        foreach (array_values($data['items']) as $position => $item) {
            if ($item['type'] === 'folder') {
                $folderPairs[] = [(int) $item['id'], $position];
            } else {
                $looseRows[] = [(int) $item['id'], null, $position];
            }
        }

        // Members carry their destination folder_id (the axis that makes this a
        // refile, not just a reorder) and a per-folder position.
        $memberRows = []; // [id, folder_id, position]
        $referencedFolderIds = array_column($folderPairs, 0);
        foreach ($data['folders'] ?? [] as $folder) {
            $folderId = (int) $folder['id'];
            $referencedFolderIds[] = $folderId;
            foreach (array_values($folder['members']) as $position => $memberId) {
                $memberRows[] = [(int) $memberId, $folderId, $position];
            }
        }
        $subtrees = $data['subtrees'] ?? [];

        // Folders created this session, keyed by their client temp id (negative).
        $newFolderNames = [];
        foreach ($data['newFolders'] ?? [] as $nf) {
            $newFolderNames[(int) $nf['id']] = trim($nf['name']);
        }

        // Guards — every REAL folder belongs to THIS workspace, every referenced
        // temp id was actually declared in newFolders, every reordered page is a
        // live page here, no page holds two slots, and the nesting payload is
        // acyclic. Membership legality is derived, not asserted against the current
        // state — refiling is the whole point.
        $realFolderIds = array_values(array_filter(array_unique($referencedFolderIds), fn ($id) => $id > 0));
        $tempFolderIds = array_values(array_filter(array_unique($referencedFolderIds), fn ($id) => $id < 0));
        if ($realFolderIds && $workspace->folders()->whereIn('id', $realFolderIds)->count() !== count($realFolderIds)) {
            throw ValidationException::withMessages(['items' => 'Unknown folder in the reorder.']);
        }
        foreach ($tempFolderIds as $tempId) {
            if (! isset($newFolderNames[$tempId])) {
                throw ValidationException::withMessages(['items' => 'A new folder was referenced but not declared.']);
            }
        }
        if ($newFolderNames) {
            $this->authorize('create', DocumentFolder::class);
        }
        $pageRows = array_merge($looseRows, $memberRows);
        $this->assertReorderablePages($workspace, array_column($pageRows, 0));
        $this->assertNestable($workspace, $subtrees, array_column($pageRows, 0));

        // Detect the refiles BEFORE writing: a page whose folder_id changes is
        // audited; a pure reorder is not. One query for the current state.
        $targetFolderOf = [];
        foreach ($pageRows as [$id, $folderId]) {
            $targetFolderOf[$id] = $folderId;
        }
        $current = Document::whereIn('id', array_keys($targetFolderOf))
            ->get(['id', 'title', 'folder_id', 'parent_id']);
        $refiled = $current->filter(fn (Document $d) => $targetFolderOf[$d->id] !== $d->folder_id);

        // A currently-nested page presented at the top level (loose or a folder
        // member) is being UN-NESTED — its parent_id must be cleared so it lands as
        // a real root page. Un-nesting to loose is a structural re-parent, audited
        // as workspace.restructured (parity with the no-folder restructure path); a
        // subpage dragged straight INTO a folder is already covered by its own
        // document.moved, so it is not counted here a second time.
        $reparented    = $current->filter(fn (Document $d) => $d->parent_id !== null);
        $unnestedLoose = $reparented->filter(fn (Document $d) => $targetFolderOf[$d->id] === null);
        $reparentedIds = $reparented->pluck('id')->all();

        // Each real folder change is a per-page mutation — authorize it like the
        // standalone refile does (a pure reorder rides the workspace gate above).
        foreach ($refiled as $document) {
            $this->authorize('update', $document);
        }

        $subtreeIds = array_column($subtrees, 'id');

        // Temp folder id (negative, client-assigned) -> real id, filled as the new
        // folders are created inside the transaction so the SAME atomic save that
        // reorders can also CREATE the folders a deferred Edit session added.
        $tempMap = [];

        DB::transaction(function () use ($workspace, $tempFolderIds, $newFolderNames, $folderPairs, $pageRows, $subtrees, $subtreeIds, $reparentedIds, &$tempMap) {
            // Create the session's new folders first, so their real ids are known
            // before the order/refile writes reference them. Position is set by the
            // assign() below from the interleaved order, so a bare 0 here is fine.
            foreach ($tempFolderIds as $tempId) {
                $tempMap[$tempId] = $workspace->folders()->create([
                    'name' => $newFolderNames[$tempId], 'position' => 0,
                ])->id;
            }
            // Swap every temp folder id for its real one in the folder slots and the
            // members' destination folder — real ids (and null) pass through untouched.
            $mapFolder   = fn ($id) => $id !== null ? ($tempMap[$id] ?? $id) : null;
            $folderPairs = array_map(fn ($p) => [$mapFolder($p[0]), $p[1]], $folderPairs);
            $pageRows    = array_map(fn ($r) => [$r[0], $mapFolder($r[1]), $r[2]], $pageRows);

            // Clear folder_id on nested pages FIRST: a subtree node about to get a
            // parent_id must shed any folder membership, or the root-only DB CHECK
            // (folder_id IS NULL OR parent_id IS NULL) trips at the tree() write.
            // A raw UPDATE keeps it structural (no updated_at bump).
            if ($subtreeIds) {
                DB::table('documents')->whereIn('id', $subtreeIds)
                    ->whereNotNull('folder_id')->update(['folder_id' => null]);
            }
            if ($subtrees) {
                BulkReorder::tree('documents', $subtrees);
            }
            // The mirror clear: a page presented at the top level is a root page, so
            // shed any parent_id it still carries (un-nest) BEFORE container() sets
            // its folder_id — otherwise a subpage filed straight into a folder would
            // trip the same root-only CHECK. Also a raw UPDATE, so it stays structural.
            if ($reparentedIds) {
                DB::table('documents')->whereIn('id', $reparentedIds)
                    ->update(['parent_id' => null]);
            }
            BulkReorder::assign('document_folders', $folderPairs);
            BulkReorder::container('documents', 'folder_id', $pageRows);
        });

        // Names for both ends of every move: the destination folders named in the
        // payload (real + freshly created) AND each refiled page's SOURCE folder,
        // which may not appear in the payload when a page is dragged out to loose.
        $folderNames = DocumentFolder::whereIn('id', array_merge(
            $realFolderIds,
            array_values($tempMap),
            $refiled->pluck('folder_id')->filter()->all(),
        ))->pluck('name', 'id');

        // The new folders are their own audit event, recorded at Done only — a
        // cancelled Edit session never reaches here, so no folder.created lingers
        // for a folder the user discarded.
        foreach (DocumentFolder::whereIn('id', array_values($tempMap))->get() as $folder) {
            \App\Support\Audit::record('folder.created', $folder, [
                'name'      => $folder->name,
                'workspace' => $workspace->name,
            ]);
        }

        foreach ($refiled as $document) {
            // A page filed into a brand-new folder targets its temp id — resolve it
            // to the real one for the move's destination name.
            $to = $targetFolderOf[$document->id];
            $to = $to !== null ? ($tempMap[$to] ?? $to) : null;
            \App\Support\Audit::record('document.moved', $document, [
                'title'       => $document->title,
                'from_folder' => $document->folder_id ? $folderNames[$document->folder_id] ?? null : null,
                'to_folder'   => $to ? $folderNames[$to] ?? null : null,
            ]);
        }

        // Re-nesting OR un-nesting a page is a structural change to the tree —
        // audited like the dedicated restructure path. A pure order change (no
        // subtrees, no un-nest to loose) is layout noise, so it stays unaudited,
        // like sibling and folder reorders. A subpage filed straight into a folder
        // is its own document.moved above, not counted again here.
        $restructureCount = count($subtrees) + $unnestedLoose->count();
        if ($restructureCount > 0) {
            \App\Support\Audit::record('workspace.restructured', $workspace, [
                'name'       => $workspace->name,
                'page_count' => $restructureCount,
            ]);
        }

        return back();
    }

    /**
     * Assert each subtree node is a page in this workspace whose parent is also a
     * page in this workspace, with no cycle among the reordered nodes, and that no
     * page is claimed by both a container slot and a nesting slot ($topLevelIds =
     * the loose + member page ids). A subtree node's folder_id is cleared before
     * it is nested (root-only is schema-enforced), so nesting a currently-filed
     * page is allowed here.
     */
    private function assertNestable(Workspace $workspace, array $subtrees, array $topLevelIds): void
    {
        if (! $subtrees) {
            return;
        }

        $ids       = array_column($subtrees, 'id');
        $parentIds = array_column($subtrees, 'parent_id');
        $wsPageIds = $workspace->documents()->pluck('id')->flip();

        foreach (array_merge($ids, $parentIds) as $id) {
            if (! isset($wsPageIds[$id])) {
                throw ValidationException::withMessages(['subtrees' => 'A nested page must belong to this workspace.']);
            }
        }

        // A page can hold at most one slot — a top-level/member position AND a
        // nesting slot would race for its parent_id/folder_id.
        if (array_intersect($ids, $topLevelIds)) {
            throw ValidationException::withMessages(['subtrees' => 'A page cannot be both reordered and nested.']);
        }

        // Cycle guard over the payload's parent map; a parent outside the payload
        // is a root and ends the chain.
        $parentOf = [];
        foreach ($subtrees as $node) {
            $parentOf[$node['id']] = $node['parent_id'];
        }
        foreach ($parentOf as $id => $parentId) {
            $seen = [];
            while ($parentId !== null) {
                if ($parentId === $id || isset($seen[$parentId])) {
                    throw ValidationException::withMessages(['subtrees' => 'A page cannot be nested inside itself.']);
                }
                $seen[$parentId] = true;
                $parentId = $parentOf[$parentId] ?? null;
            }
        }
    }

    /**
     * Assert every id is a live page in this workspace, and that no id appears
     * twice — a page holding two slots would get two conflicting positions/folders.
     * Neither the folder side NOR the parent side is checked: a reorder may refile
     * AND un-nest, so a page presented at the top level is made a root page (its
     * parent_id is cleared in the write), whatever it was before.
     */
    private function assertReorderablePages(Workspace $workspace, array $ids): void
    {
        if (! $ids) {
            return;
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'A page appears twice in the reorder.']);
        }

        if ($workspace->documents()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'items' => 'A reordered page is not a live page in this workspace.',
            ]);
        }
    }

    /** Reorder siblings by assigning positions from the given id order. */
    public function reorder(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:documents,id'],
        ]);

        // Authorize every page up front (one query), then write all positions in
        // a single statement instead of a find + update per id.
        foreach (Document::findMany($data['ids']) as $document) {
            $this->authorize('update', $document);
        }

        BulkReorder::positions('documents', $data['ids']);

        return back();
    }

    /** True if making $parentId the parent of $document would create a cycle. */
    protected function wouldCycle(Document $document, int $parentId): bool
    {
        if ($parentId === $document->getKey()) {
            return true;
        }

        $seen = [];
        $ancestor = Document::find($parentId);

        while ($ancestor) {
            // Stop if pre-existing corrupt data already loops, instead of hanging.
            if (isset($seen[$ancestor->getKey()])) {
                break;
            }
            $seen[$ancestor->getKey()] = true;

            if ($ancestor->getKey() === $document->getKey()) {
                return true;
            }
            $ancestor = $ancestor->parent_id ? Document::find($ancestor->parent_id) : null;
        }

        return false;
    }
}
