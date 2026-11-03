<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Audit tab — read-only view over the append-only audit trail. There are
 * deliberately no write endpoints here: events are only ever created through
 * App\Support\Audit and only removed by `audit:prune`.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user'      => ['nullable', 'integer'],
            'event'     => ['nullable', 'string', 'max:100'],
            'workspace' => ['nullable', 'integer'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
        ]);

        $events = AuditEvent::query()
            ->with('user:id,name,avatar_color')
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            // Filter by full name (document.updated) or namespace prefix (document).
            ->when($filters['event'] ?? null, fn ($q, $event) => str_contains($event, '.')
                ? $q->where('event', $event)
                : $q->where('event', 'like', $event . '.%'))
            ->when($filters['workspace'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', \Carbon\Carbon::parse($to)->addDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $e) => [
                'id'         => $e->id,
                'event'      => $e->event,
                'user'       => $e->user?->only('id', 'name', 'avatar_color'),
                'context'    => $e->context,
                'ip'         => $e->ip,
                'created_at' => $e->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/Audit', [
            'events'  => $events,
            'filters' => $filters,
            // Filter sources. Event namespaces stay in sync with what exists.
            'users'      => User::orderBy('name')->get(['id', 'name']),
            'workspaces' => Workspace::orderBy('name')->get(['id', 'name']),
            'eventTypes' => AuditEvent::query()
                ->selectRaw("distinct split_part(event, '.', 1) as ns")
                ->orderBy('ns')
                ->pluck('ns'),
        ]);
    }
}
