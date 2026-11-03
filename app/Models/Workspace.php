<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'position', 'group_id'])]
class Workspace extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    /** The group this workspace is filed under, or null when ungrouped (top level). */
    public function group(): BelongsTo
    {
        return $this->belongsTo(WorkspaceGroup::class, 'group_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Page folders in this workspace, in display order. */
    public function folders(): HasMany
    {
        return $this->hasMany(DocumentFolder::class)->orderBy('position');
    }

    /** Top-level documents (no parent), in display order. */
    public function rootDocuments(): HasMany
    {
        return $this->hasMany(Document::class)
            ->whereNull('parent_id')
            ->orderBy('position');
    }

    /**
     * Soft-delete this workspace and every live document inside it. The whole
     * forest goes to trash together so nothing is left live-but-orphaned (which
     * would otherwise leak into search and dangling breadcrumbs).
     */
    public function trashWithDocuments(): void
    {
        // Flag each page as cascade-trashed before removing it, so restoring the
        // workspace brings back exactly these pages — not ones a user had already
        // trashed individually before the workspace itself was deleted.
        $this->documents()->get()->each(function (Document $doc) {
            $doc->flagCascadeTrashed();
            $doc->delete();
        });

        $this->delete();
    }

    /**
     * Restore this workspace and the pages it took down with it. Pages that were
     * already in the trash on their own (unflagged) are left there.
     */
    public function restoreWithDocuments(): void
    {
        $this->restore();

        $this->documents()->onlyTrashed()->get()
            ->each(function (Document $doc) {
                if ($doc->wasCascadeTrashed()) {
                    $doc->restore();
                    $doc->clearCascadeFlag();
                }
            });
    }

    /** Permanently delete this workspace and all its documents (live or trashed). */
    public function forceDeleteWithDocuments(): void
    {
        $this->documents()->withTrashed()->get()->each->forceDelete();

        $this->forceDelete();
    }
}
