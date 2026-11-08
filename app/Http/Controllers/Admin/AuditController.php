<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Console\Commands\PruneAuditEvents;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Audit tab — view and export the append-only audit trail, and set its
 * retention. Events are only ever created through App\Support\Audit and only
 * removed by `audit:prune`.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $events = $this->filteredQuery($filters)
            ->with('user:id,name,avatar_color')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditEvent $e) => [
                'id'         => $e->id,
                'event'      => $e->event,
                'user'       => $e->user?->only('id', 'name', 'avatar_color')
                    ?? ($e->actor_name ? ['id' => null, 'name' => $e->actor_name, 'avatar_color' => null] : null),
                'context'    => $e->context,
                'ip'         => $e->ip,
                'created_at' => $e->created_at->toIso8601String(),
            ]);

        $settings = Setting::get('audit', []);

        return Inertia::render('Admin/Audit', [
            'events'  => $events,
            'filters' => $filters,
            'retentionDays' => $settings['retention_days'] ?? PruneAuditEvents::DEFAULT_RETENTION_DAYS,
            'chain' => [
                'verified_at' => $settings['verified_at'] ?? null,
                'ok'          => $settings['verified_ok'] ?? null,
                'rows'        => $settings['verified_rows'] ?? null,
            ],
            // Filter sources. Event namespaces stay in sync with what exists.
            'users'      => User::orderBy('name')->get(['id', 'name']),
            'workspaces' => Workspace::orderBy('name')->get(['id', 'name']),
            'eventTypes' => AuditEvent::query()
                ->selectRaw("distinct split_part(event, '.', 1) as ns")
                ->orderBy('ns')
                ->pluck('ns'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
        ]);

        $settings = Setting::get('audit', []);
        $from = $settings['retention_days'] ?? PruneAuditEvents::DEFAULT_RETENTION_DAYS;
        Setting::put('audit', array_merge($settings, ['retention_days' => $validated['retention_days']]));

        if ($from !== $validated['retention_days']) {
            Audit::record('settings.audit_updated', null, ['from' => $from, 'to' => $validated['retention_days']]);
        }

        return back()->with('success', 'Audit retention updated.');
    }

    /** Download the filtered trail as CSV, for an auditor or an off-system copy. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        Audit::record('audit.exported', null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'event', 'user_id', 'actor_name', 'actor_email', 'ip', 'workspace_id', 'subject_type', 'subject_id', 'context', 'prev_hash', 'row_hash']);

            foreach ($this->filteredQuery($filters)->lazyById(500) as $e) {
                fputcsv($out, array_map($this->csvSafe(...), [
                    $e->id, $e->created_at->toIso8601String(), $e->event, $e->user_id, $e->actor_name, $e->actor_email,
                    $e->ip, $e->workspace_id, $e->auditable_type, $e->auditable_id,
                    $e->context ? json_encode($e->context, JSON_UNESCAPED_UNICODE) : null,
                    $e->prev_hash, $e->row_hash,
                ]));
            }

            fclose($out);
        }, 'audit-trail-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'user'      => ['nullable', 'integer'],
            'event'     => ['nullable', 'string', 'max:100'],
            'workspace' => ['nullable', 'integer'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
        ]);
    }

    private function filteredQuery(array $filters): Builder
    {
        return AuditEvent::query()
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            // Filter by full name (document.updated) or namespace prefix (document).
            ->when($filters['event'] ?? null, fn ($q, $event) => str_contains($event, '.')
                ? $q->where('event', $event)
                : $q->where('event', 'like', $event . '.%'))
            ->when($filters['workspace'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', \Carbon\Carbon::parse($to)->addDay()));
    }

    /** Stop a logged value (such as a failed-login email) running as a spreadsheet formula. */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
