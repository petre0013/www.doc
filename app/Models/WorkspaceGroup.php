<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single-level container that groups workspaces in the sidebar (BookStack
 * "shelf"). A workspace belongs to at most one group; groups never nest, and
 * they own no content — deleting a group reverts its workspaces to ungrouped,
 * never trashes them (see WorkspaceGroupController::destroy).
 *
 * Groups are NOT soft-deleted: they carry no content, have no Trash/restore UI,
 * and a lingering trashed row would only block reusing the same name (HasSlug
 * counts trashed slugs). Delete is cheap to undo — recreate and re-file.
 */
#[Fillable(['name', 'slug', 'position'])]
class WorkspaceGroup extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceGroupFactory> */
    use HasFactory, HasSlug;

    /** Workspaces filed under this group, in display order. */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'group_id')->orderBy('position');
    }
}
