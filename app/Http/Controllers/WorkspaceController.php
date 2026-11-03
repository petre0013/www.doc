<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Support\Audit;
use App\Support\BulkReorder;
use App\Support\DocumentTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Workspace::class);

        $workspaces = Workspace::query()
            ->withCount('documents')
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'position', 'group_id', 'updated_at']);

        // Groups (BookStack-style shelves) in display order; the page composes the
        // grouped view client-side from these + each workspace's group_id.
        $groups = WorkspaceGroup::orderBy('position')->orderBy('name')
            ->get(['id', 'name', 'slug', 'position']);

        $recent = Document::with('workspace')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get()
            ->map(fn (Document $d) => [
                'id'         => $d->id,
                'title'      => $d->title,
                'updated_at' => $d->updated_at->toIso8601String(),
                'workspace'  => ['name' => $d->workspace->name, 'slug' => $d->workspace->slug],
            ]);

        // Personal quick access (document_user pivot). Trashed pages drop out
        // via Document's soft-delete scope on the relations.
        $user = auth()->user();
        $docRow = fn (Document $d) => [
            'id'        => $d->id,
            'title'     => $d->title,
            'workspace' => ['name' => $d->workspace->name],
        ];

        $starred = $user->starredDocuments()->with('workspace:id,name')
            ->get()->map($docRow)->values();

        // Pivot columns aren't cast: last_viewed_at arrives as a bare
        // "Y-m-d H:i:s" string, which browsers parse as LOCAL time — every
        // row would be off by the viewer's UTC offset. Ship it as ISO 8601
        // with timezone like every other date prop.
        $recentlyViewed = $user->recentlyViewedDocuments()->with('workspace:id,name')
            ->limit(15)->get()
            ->map(fn (Document $d) => $docRow($d) + [
                'viewed_at' => Carbon::parse($d->pivot->last_viewed_at)->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Workspaces/Index', [
            'workspaces'     => $workspaces,
            'groups'         => $groups,
            'recent'         => $recent,
            'starred'        => $starred,
            'recentlyViewed' => $recentlyViewed,
        ]);
    }

    public function show(Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);

        $documents = $workspace->documents()->with('tags')->orderBy('position')->get();

        return Inertia::render('Workspaces/Show', [
            'workspace' => $workspace->loadCount('documents'),
            'tree'      => DocumentTree::build($documents),
            // Page folders (containers that are NOT pages). The page composes the
            // sections client-side from these + each root page's folder_id.
            'folders'   => $workspace->folders()->get(['id', 'name', 'position']),
            // For the tree rows' star affordance (personal, per-user).
            'starredIds' => DB::table('document_user')
                ->where('user_id', auth()->id())
                ->whereNotNull('starred_at')
                ->whereIn('document_id', $documents->pluck('id'))
                ->pluck('document_id'),
            // For the New page modal's "start from" picker. Viewers can't
            // create pages (and can't view templates), so they get none.
            'templates' => auth()->user()->can('viewAny', \App\Models\Template::class)
                ? \App\Models\Template::orderBy('name')->get(['id', 'name', 'description'])
                : [],
        ]);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        // Validate the payload like the document reorder/move endpoints do — an
        // unchecked id list reaching a raw UPDATE is the one gap among the tree
        // ops. The bulk write sets all positions in one statement and leaves
        // updated_at untouched (reordering is structural, not a content edit).
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        BulkReorder::positions('workspaces', $data['ids']);

        return back();
    }

    /**
     * Reorder the top level of the workspaces index AND refile workspaces between
     * groups, in one atomic structural save. Groups and ungrouped workspaces are
     * interleaved in a SINGLE shared order (`items`); each group's members ride
     * the `groups` companion axis, tagged with the group they now belong to — so a
     * drag that carries a workspace from one group into another (or out to loose)
     * lands its new `group_id` in the same "Done" as the reordering.
     *
     * A workspace's target group is derived from WHERE it appears: a `items`
     * workspace becomes loose (group_id = null); a `groups[].members` workspace
     * joins that group. Positions are global sort keys: top-level slots take their
     * index in `items` (so a loose workspace can sit between two groups), members
     * take their index within their group.
     *
     * Structural throughout — the raw writes never touch updated_at. Reordering is
     * layout noise and stays unaudited, but a workspace whose group_id actually
     * changes is a move, and each such move records ONE `workspace.moved` (parity
     * with the standalone regroup endpoint and with document.moved).
     */
    public function reorderTopLevel(Request $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);
        $this->authorize('create', WorkspaceGroup::class);

        $data = $request->validate([
            'items'              => ['required', 'array'],
            'items.*.type'       => ['required', 'string', 'in:group,workspace'],
            'items.*.id'         => ['required', 'integer'],
            // Each group with its ordered members. Replaces the old flat `grouped`
            // list: the group id is what lets a member be REFILED here, not just
            // reordered.
            'groups'             => ['array'],
            'groups.*.id'        => ['required', 'integer'],
            'groups.*.members'   => ['present', 'array'],
            'groups.*.members.*' => ['integer'],
        ]);

        // Group slots (position = index in the interleaved top level) + the loose
        // workspaces that share that same index space.
        $groupPairs = [];
        $looseRows  = []; // [id, group_id (null), position]
        foreach (array_values($data['items']) as $position => $item) {
            if ($item['type'] === 'group') {
                $groupPairs[] = [(int) $item['id'], $position];
            } else {
                $looseRows[] = [(int) $item['id'], null, $position];
            }
        }

        // Members carry their destination group_id (the axis that makes this a
        // refile, not just a reorder) and a per-group position.
        $memberRows = []; // [id, group_id, position]
        $referencedGroupIds = array_column($groupPairs, 0);
        foreach ($data['groups'] ?? [] as $group) {
            $groupId = (int) $group['id'];
            $referencedGroupIds[] = $groupId;
            foreach (array_values($group['members']) as $position => $memberId) {
                $memberRows[] = [(int) $memberId, $groupId, $position];
            }
        }

        // Guard the raw writes: every group exists, every workspace is a live
        // workspace, and no workspace holds two slots (which would race for a
        // position). Membership legality is derived, not asserted against the
        // current state — refiling is the whole point.
        $this->assertIdsExist('workspace_groups', $referencedGroupIds);
        $workspaceRows = array_merge($looseRows, $memberRows);
        $this->assertLiveWorkspaces(array_column($workspaceRows, 0));

        // Detect the moves BEFORE writing: a workspace whose group_id changes is
        // audited; a pure reorder is not. One query for the current state.
        $targetGroupOf = [];
        foreach ($workspaceRows as [$id, $groupId]) {
            $targetGroupOf[$id] = $groupId;
        }
        $current = Workspace::whereIn('id', array_keys($targetGroupOf))
            ->get(['id', 'name', 'group_id']);
        $moved = $current->filter(fn (Workspace $w) => $targetGroupOf[$w->id] !== $w->group_id);

        // Each real group change is a per-workspace mutation — authorize it like
        // the standalone regroup does (a pure reorder rides the endpoint's
        // create-level gate above).
        foreach ($moved as $workspace) {
            $this->authorize('update', $workspace);
        }

        // Names for both ends of every move: the destination groups named in the
        // payload AND each moved workspace's SOURCE group, which may not appear in
        // the payload when a workspace is simply dragged out to the top level.
        $groupNames = WorkspaceGroup::whereIn('id',
            array_merge($referencedGroupIds, $moved->pluck('group_id')->filter()->all())
        )->pluck('name', 'id');

        DB::transaction(function () use ($groupPairs, $workspaceRows) {
            BulkReorder::assign('workspace_groups', $groupPairs);
            BulkReorder::container('workspaces', 'group_id', $workspaceRows);
        });

        foreach ($moved as $workspace) {
            $to = $targetGroupOf[$workspace->id];
            Audit::record('workspace.moved', $workspace, [
                'name'       => $workspace->name,
                'from_group' => $workspace->group_id ? $groupNames[$workspace->group_id] ?? null : null,
                'to_group'   => $to ? $groupNames[$to] ?? null : null,
            ]);
        }

        return back();
    }

    /** @param array<int, int> $ids */
    private function assertIdsExist(string $table, array $ids): void
    {
        if ($ids && \Illuminate\Support\Facades\DB::table($table)->whereIn('id', array_unique($ids))->count() !== count(array_unique($ids))) {
            abort(422, 'Unknown '.$table.' id in reorder payload.');
        }
    }

    /**
     * Every id must be a live workspace, and each may appear only ONCE across the
     * whole payload — a workspace holding both a loose slot and a member slot
     * would get two conflicting positions/groups. @param array<int, int> $ids
     */
    private function assertLiveWorkspaces(array $ids): void
    {
        if (! $ids) {
            return;
        }

        if (count($ids) !== count(array_unique($ids))) {
            abort(422, 'A workspace appears twice in the reorder payload.');
        }
        if (Workspace::whereIn('id', $ids)->count() !== count($ids)) {
            abort(422, 'Reorder payload includes an unknown workspace.');
        }
    }

    /**
     * File a workspace into a group (or out to the top level). Mirrors
     * DocumentController@move: changing the container is structural, so it must
     * NOT bump updated_at, and only a real group change (not a position-only
     * tweak) is audited. The client appends to the destination via `position`;
     * within-group ordering is handled by the reorder endpoint.
     */
    public function regroup(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'group_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('workspace_groups', 'id'),
            ],
            'position' => ['nullable', 'integer'],
        ]);

        $groupId = $data['group_id'] ?? null;
        $groupChanged = $groupId !== $workspace->group_id;
        $fromGroup = $workspace->group?->name;

        $workspace->group_id = $groupId;
        $workspace->position = $data['position'] ?? $workspace->position;

        // Structural move must not shift updated_at — only content edits should.
        Workspace::withoutTimestamps(fn () => $workspace->save());

        // Re-grouping is audited (parity with document.moved); a position-only
        // change (same group) is layout noise and is not.
        if ($groupChanged) {
            Audit::record('workspace.moved', $workspace, [
                'name'       => $workspace->name,
                'from_group' => $fromGroup,
                'to_group'   => $groupId ? WorkspaceGroup::find($groupId)?->name : null,
            ]);
        }

        return back();
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $data = $request->validated();

        // A new workspace lands at the TOP of its context so it's visible without
        // scrolling: above every group and loose workspace when ungrouped, or
        // above its siblings when created into a group. Positions are only sort
        // keys (a later reorder renumbers 0..n), so one below the current minimum
        // is enough — no need to shift existing rows down.
        if (! isset($data['position'])) {
            $data['position'] = $this->topOfOrder($data['group_id'] ?? null);
        }

        $workspace = Workspace::create($data);

        // Records the group it was filed into at birth; a later move is its own
        // workspace.moved event. Null (ungrouped) simply reads as "created".
        Audit::record('workspace.created', $workspace, [
            'name'  => $workspace->name,
            'group' => $workspace->group?->name,
        ]);

        return redirect()->route('workspaces.show', $workspace);
    }

    /**
     * One below the lowest position in the new workspace's context — the shared
     * top-level order (groups + ungrouped workspaces) when $groupId is null, or the
     * group's own members otherwise. 0 when that context is empty. Mirrors the
     * array_filter/min shape used by WorkspaceGroupController and the folder/page
     * "create at top" paths, so a null axis is dropped rather than coerced to 0.
     */
    private function topOfOrder(?int $groupId): int
    {
        $mins = $groupId === null
            ? [WorkspaceGroup::min('position'), Workspace::whereNull('group_id')->min('position')]
            : [Workspace::where('group_id', $groupId)->min('position')];

        $mins = array_filter($mins, fn ($p) => $p !== null);

        return ($mins ? min($mins) : 0) - 1;
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $original = $workspace->only(['name', 'description']);
        $workspace->update($request->validated());

        // One event per save: a rename is the notable action (and carries any
        // description change alongside); a description-only edit is workspace.updated.
        if ($workspace->wasChanged('name')) {
            $context = ['from' => $original['name'], 'to' => $workspace->name];
            if ($workspace->wasChanged('description')) {
                $context['description_from'] = $original['description'];
                $context['description_to']   = $workspace->description;
            }
            Audit::record('workspace.renamed', $workspace, $context);
        } elseif ($workspace->wasChanged('description')) {
            Audit::record('workspace.updated', $workspace, [
                'name' => $workspace->name,
                'from' => $original['description'],
                'to'   => $workspace->description,
            ]);
        }

        return back();
    }

    public function destroy(Workspace $workspace): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        $workspace->trashWithDocuments();

        Audit::record('workspace.trashed', $workspace, ['name' => $workspace->name]);

        return redirect()->route('workspaces.index')
            ->with('success', "Moved \"{$workspace->name}\" to trash.");
    }
}
