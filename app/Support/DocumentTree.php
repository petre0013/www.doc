<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Builds a nested document tree from a flat collection, without N+1 queries.
 */
class DocumentTree
{
    /**
     * @param  iterable<\App\Models\Document>  $documents  All documents in a workspace.
     * @return array<int, array<string, mixed>>
     */
    public static function build(iterable $documents): array
    {
        $byParent = Collection::make($documents)
            ->sortBy('position')
            ->groupBy(fn ($doc) => $doc->parent_id ?? 0);

        return self::branch($byParent, 0);
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection>  $byParent
     * @return array<int, array<string, mixed>>
     */
    protected static function branch(Collection $byParent, int $parentId): array
    {
        return $byParent->get($parentId, collect())
            ->map(fn ($doc) => [
                'id'         => $doc->id,
                'title'      => $doc->title,
                'slug'       => $doc->slug,
                'position'   => $doc->position,
                // Only ever set on root pages (schema-enforced); the tree view
                // reads it at depth 0 to group pages under their folder.
                'folder_id'  => $doc->folder_id,
                'updated_at' => $doc->updated_at?->diffForHumans(),
                'tags'       => $doc->tags
                    ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                    ->values()
                    ->all(),
                'children'   => self::branch($byParent, $doc->id),
            ])
            ->values()
            ->all();
    }
}
