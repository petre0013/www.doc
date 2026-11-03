<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

/**
 * Templates only matter to people who can create pages, so viewers don't see
 * them at all; editors and admins manage them fully (delete included — unlike
 * tags, removing a template never touches existing pages).
 */
class TemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function view(User $user, Template $template): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, Template $template): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function delete(User $user, Template $template): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }
}
