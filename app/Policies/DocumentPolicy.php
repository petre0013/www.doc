<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor', 'viewer']);
    }

    public function view(User $user, Document $document): Response|bool
    {
        return $this->inWorkspace($user, $document->workspace_id, ['admin', 'editor', 'viewer']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, Document $document): Response|bool
    {
        return $this->inWorkspace($user, $document->workspace_id, ['admin', 'editor']);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $user->hasRole('admin');
    }
}
