<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Persist a reordering in ONE statement instead of an UPDATE per row.
 *
 * Reordering is a structural change, so these writes deliberately do NOT touch
 * `updated_at` — a raw UPDATE leaves timestamps alone (unlike Eloquent saves).
 * Values arrive as bound placeholders (untyped → text in Postgres), so each
 * column is cast back to its real type in the join.
 *
 * `$table` is always a hardcoded literal at the call sites — never user input.
 */
class BulkReorder
{
    /** Assign position = list index to each id, in their given order. */
    public static function positions(string $table, array $idsInOrder): void
    {
        $rows = [];
        $bindings = [];
        foreach (array_values($idsInOrder) as $position => $id) {
            $rows[] = '(?, ?)';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        if (! $rows) {
            return;
        }

        DB::update(
            "UPDATE {$table} AS t SET position = v.position::int "
            .'FROM (VALUES '.implode(',', $rows).') AS v(id, position) '
            .'WHERE t.id = v.id::bigint',
            $bindings,
        );
    }

    /**
     * Assign an explicit position to each id in one statement.
     *
     * Unlike positions(), the caller supplies the exact position per id rather
     * than deriving it from list order — used when several tables share one
     * ordering space (top-level groups + ungrouped workspaces) and each row's
     * position is a global index computed across all of them.
     *
     * @param array<int, array{0: int, 1: int}> $idPositionPairs list of [id, position]
     */
    public static function assign(string $table, array $idPositionPairs): void
    {
        $rows = [];
        $bindings = [];
        foreach ($idPositionPairs as [$id, $position]) {
            $rows[] = '(?, ?)';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        if (! $rows) {
            return;
        }

        DB::update(
            "UPDATE {$table} AS t SET position = v.position::int "
            .'FROM (VALUES '.implode(',', $rows).') AS v(id, position) '
            .'WHERE t.id = v.id::bigint',
            $bindings,
        );
    }

    /**
     * Set a container FK column + position for each row in ONE statement — the
     * refiling counterpart to positions()/assign(), which only move a row within
     * its current container. Each row is [id, containerId (nullable), position];
     * $column is a hardcoded literal at the call site ('group_id' / 'folder_id'),
     * never user input. Structural like the rest of this class: a raw UPDATE that
     * leaves updated_at (and any optimistic-lock `version`) untouched.
     *
     * @param array<int, array{0: int, 1: ?int, 2: int}> $rows list of [id, containerId, position]
     */
    public static function container(string $table, string $column, array $rows): void
    {
        $values = [];
        $bindings = [];
        foreach ($rows as [$id, $containerId, $position]) {
            $values[] = '(?, ?, ?)';
            $bindings[] = $id;
            $bindings[] = $containerId;
            $bindings[] = $position;
        }

        if (! $values) {
            return;
        }

        DB::update(
            "UPDATE {$table} AS t SET {$column} = v.container::bigint, position = v.position::int "
            .'FROM (VALUES '.implode(',', $values).') AS v(id, container, position) '
            .'WHERE t.id = v.id::bigint',
            $bindings,
        );
    }

    /**
     * Set parent_id + position for each node in one statement.
     * Each node is ['id' => int, 'parent_id' => ?int, 'position' => int].
     */
    public static function tree(string $table, array $nodes): void
    {
        $rows = [];
        $bindings = [];
        foreach ($nodes as $node) {
            $rows[] = '(?, ?, ?)';
            $bindings[] = $node['id'];
            $bindings[] = $node['parent_id'] ?? null;
            $bindings[] = $node['position'];
        }

        if (! $rows) {
            return;
        }

        DB::update(
            "UPDATE {$table} AS t SET parent_id = v.parent_id::bigint, position = v.position::int "
            .'FROM (VALUES '.implode(',', $rows).') AS v(id, parent_id, position) '
            .'WHERE t.id = v.id::bigint',
            $bindings,
        );
    }
}
