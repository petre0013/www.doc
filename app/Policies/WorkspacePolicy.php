<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use Illuminate\Auth\Access\Response;

class WorkspacePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor', 'viewer']);
    }

    public function view(User $user, Workspace $workspace): Response|bool
    {
        return $this->inWorkspace($user, $workspace->id, ['admin', 'editor', 'viewer']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, Workspace $workspace): Response|bool
    {
        return $this->inWorkspace($user, $workspace->id, ['admin', 'editor']);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $user->hasRole('admin');
    }
}
