<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for the append-only audit trail. Call from controllers,
 * observers, commands, and jobs — never write AuditEvent rows directly.
 *
 * Event names are dot-namespaced `subject.action` (document.updated,
 * backup.restored, auth.login). Keep the namespace stable: the admin UI
 * filters on the prefix, and future features (ACLs) will add their own.
 */
class Audit
{
    /**
     * @param  Model|null  $subject  What the event is about; captured as a loose
     *                               morph so the row outlives purges.
     * @param  array  $context  Human-readable snapshot (titles, old/new values).
     * @param  int|null  $actorId  Override for queue/console contexts where
     *                             Auth::id() is empty (jobs carry created_by).
     * @param  string|null  $ip  Override for the same reason: a job has no
     *                           request, so an action a human started from a
     *                           browser must carry the IP captured back then
     *                           (jobs store it alongside the actor). Scheduled
     *                           and console runs pass nothing and stay null.
     */
    public static function record(string $event, ?Model $subject = null, array $context = [], ?int $actorId = null, ?string $ip = null): AuditEvent
    {
        $actorId ??= Auth::id();
        $actor = $actorId ? (Auth::id() === $actorId ? Auth::user() : User::find($actorId)) : null;

        // The lock is held until commit, so each new row links to the true
        // previous row even when requests write concurrently.
        return DB::transaction(function () use ($event, $subject, $context, $actorId, $actor, $ip) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [AuditChain::LOCK_KEY]);

            $row = new AuditEvent([
                'user_id'        => $actorId,
                'actor_name'     => $actor?->name,
                'actor_email'    => $actor?->email,
                'event'          => $event,
                'auditable_type' => $subject?->getMorphClass(),
                'auditable_id'   => $subject?->getKey(),
                'workspace_id'   => self::workspaceId($subject),
                'context'        => $context ?: null,
                'ip'             => $ip ?? (app()->runningInConsole() ? null : request()->ip()),
                'created_at'     => now(),
            ]);

            $previous = DB::table('audit_events')->whereNotNull('row_hash')->orderByDesc('id')->value('row_hash');
            $row->prev_hash = $previous;
            $row->row_hash = AuditChain::hash($row->getAttributes(), $previous);
            $row->save();

            return $row;
        });
    }

    /** Derive the workspace an event belongs to, for the admin UI's filter. */
    private static function workspaceId(?Model $subject): ?int
    {
        if ($subject instanceof Workspace) {
            return $subject->getKey();
        }

        return $subject?->getAttribute('workspace_id');
    }
}
