<?php

namespace App\Policies;

use App\Models\DocumentFolder;
use App\Models\User;

/**
 * Folders are organizational containers, not content — the same footing as
 * WorkspaceGroup, and gated the same way (admin + editor). Deleting is
 * non-destructive (pages revert to loose, never trashed), so it needs no
 * stricter gate than renaming.
 */
class DocumentFolderPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function update(User $user, DocumentFolder $folder): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }

    public function delete(User $user, DocumentFolder $folder): bool
    {
        return $user->hasAnyRole(['admin', 'editor']);
    }
}
