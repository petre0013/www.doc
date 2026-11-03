<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single-level container for pages inside a workspace — a folder that is NOT
 * a page. It has no body, no slug and no URL because it can never be opened:
 * its name only toggles the section. Folders never nest, and a page belongs to
 * at most one (mirroring WorkspaceGroup, deliberately — the rules are identical
 * so the app doesn't grow a third set of container semantics).
 *
 * A folder owns no content: deleting one reverts its pages to loose top-level
 * pages, never trashes them (see DocumentFolderController::destroy, M2). Hence
 * no soft deletes, same reasoning as WorkspaceGroup.
 *
 * `folder_id` is meaningful only on ROOT pages; a subpage's folder is derived
 * from its root ancestor. Both that and same-workspace membership are enforced
 * in the schema (CHECK + composite FK), not by convention.
 */
#[Fillable(['workspace_id', 'name', 'position'])]
class DocumentFolder extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFolderFactory> */
    use HasFactory;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Root pages filed in this folder, in display order. */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id')->orderBy('position');
    }
}
