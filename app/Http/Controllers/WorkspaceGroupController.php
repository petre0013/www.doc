<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkspaceGroupRequest;
use App\Http\Requests\UpdateWorkspaceGroupRequest;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;

class WorkspaceGroupController extends Controller
{
    public function store(StoreWorkspaceGroupRequest $request): RedirectResponse
    {
        $this->authorize('create', WorkspaceGroup::class);

        // Land a new group at the TOP of the shared top-level order (groups +
        // ungrouped workspaces), not below its same-position siblings. Position is
        // a plain sort key, so one below the current minimum sorts it first; a
        // later reorder normalises everything back to 0-based.
        $group = WorkspaceGroup::create([
            ...$request->validated(),
            'position' => $this->topOfOrder(),
        ]);

        Audit::record('group.created', $group, ['name' => $group->name]);

        return back();
    }

    /** One below the lowest top-level position (groups + ungrouped workspaces), or 0 when empty. */
    private function topOfOrder(): int
    {
        $mins = array_filter([
            WorkspaceGroup::min('position'),
            Workspace::whereNull('group_id')->min('position'),
        ], fn ($p) => $p !== null);

        return $mins ? min($mins) - 1 : 0;
    }

    public function update(UpdateWorkspaceGroupRequest $request, WorkspaceGroup $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $from = $group->name;
        $group->update($request->validated());

        if ($group->wasChanged('name')) {
            Audit::record('group.renamed', $group, ['from' => $from, 'to' => $group->name]);
        }

        return back();
    }

    public function destroy(WorkspaceGroup $group): RedirectResponse
    {
        $this->authorize('delete', $group);

        $name = $group->name;

        // Revert members to ungrouped instead of trashing them — a group is an
        // organizational label, not an owner of content. withoutTimestamps keeps
        // each workspace's updated_at frozen: being un-filed is structural, not an
        // edit (an Eloquent mass update auto-bumps updated_at otherwise).
        Workspace::withoutTimestamps(fn () => $group->workspaces()->update(['group_id' => null]));

        $group->delete();

        // Subject is gone (hard delete) — identity rides in context so the morph
        // doesn't dangle, per audit rules.
        Audit::record('group.deleted', null, ['group_id' => $group->id, 'name' => $name]);

        return back();
    }
}
