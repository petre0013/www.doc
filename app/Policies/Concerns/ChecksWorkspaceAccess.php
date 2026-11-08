<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;

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

        return $user->canAccessWorkspace($workspaceId) ?: Response::denyAsNotFound();
    }
}
