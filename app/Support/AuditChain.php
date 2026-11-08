<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident hash chain over audit_events: each row's hash covers its own
 * fields and the previous row's hash, so editing or removing a row breaks every
 * later link. Rows written before the chain existed (null row_hash) are skipped.
 *
 * Limit: removing the newest rows leaves a valid shorter chain. Detecting that
 * needs a copy outside this database, which is what the CSV export is for.
 */
class AuditChain
{
    /** Serialises writers so the chain has exactly one order. */
    public const LOCK_KEY = 7263841;

    /** @param array<string,mixed> $row Column values as stored in the database. */
    public static function hash(array $row, ?string $previous): string
    {
        $context = $row['context'] ?? null;
        if (is_string($context)) {
            $context = json_decode($context, true);
        }

        return hash('sha256', json_encode([
            $previous,
            isset($row['user_id']) ? (int) $row['user_id'] : null,
            $row['actor_name'] ?? null,
            $row['actor_email'] ?? null,
            $row['event'],
            $row['auditable_type'] ?? null,
            isset($row['auditable_id']) ? (int) $row['auditable_id'] : null,
            isset($row['workspace_id']) ? (int) $row['workspace_id'] : null,
            self::sorted($context),
            $row['ip'] ?? null,
            (string) $row['created_at'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return array{checked:int, broken_at:?int, reason:?string} */
    public static function verify(): array
    {
        $previous = null;
        $checked = 0;

        foreach (DB::table('audit_events')->lazyById(500) as $row) {
            if ($row->row_hash === null) {
                if ($previous !== null) {
                    return ['checked' => $checked, 'broken_at' => $row->id, 'reason' => 'Row has no hash inside the chain.'];
                }

                continue;
            }

            // The first hashed row may point at a row that retention removed.
            if ($previous !== null && $row->prev_hash !== $previous) {
                return ['checked' => $checked, 'broken_at' => $row->id, 'reason' => 'Link to the previous row is broken.'];
            }

            if (! hash_equals($row->row_hash, self::hash((array) $row, $row->prev_hash))) {
                return ['checked' => $checked, 'broken_at' => $row->id, 'reason' => 'Row contents do not match its hash.'];
            }

            $previous = $row->row_hash;
            $checked++;
        }

        return ['checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /** Run a sanctioned retention delete; the DB trigger refuses any other. */
    public static function withMaintenance(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::select("SELECT set_config('app.audit_maintenance', 'on', true)");
            $result = $callback();
            DB::select("SELECT set_config('app.audit_maintenance', 'off', true)");

            return $result;
        });
    }

    /** jsonb does not preserve key order, so hash a canonical ordering. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
