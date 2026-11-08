<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Cache;

trait ChecksWorkspaceAccess
{
    /**
     * Combine the global role check with workspace membership. Non-members get
     * a 404, so a restricted page's existence isn't disclosed.
     */
    private function inWorkspace(User $user, ?int $workspaceId, array $roles): Response|bool
    {
        if (! $user->hasAnyRole($roles)) {
            return false;
        }

        if ($user->canAccessWorkspace($workspaceId)) {
            return true;
        }

        // One event per person and workspace per minute, so a page reload can't flood the trail.
        if (Cache::add("access-denied:{$user->id}:{$workspaceId}", true, 60)) {
            Audit::record('access.denied', Workspace::withTrashed()->find($workspaceId), ['path' => request()->path()]);
        }

        return Response::denyAsNotFound();
    }
}
