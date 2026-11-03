<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor', 'viewer']);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->hasAnyRole(['admin', 'editor', 'viewer']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasRole('admin');
    }
}
