<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkspaceGroup;

/**
 * Groups are organizational labels, not content. Managing them tracks workspace
 * management (admin + editor); deleting is non-destructive (workspaces revert to
 * ungrouped), so it needs no stricter gate than renaming.
 */
class WorkspaceGroupPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, WorkspaceGroup $group): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function delete(User $user, WorkspaceGroup $group): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }
}
