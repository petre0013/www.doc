<?php

namespace App\Http\Middleware;

use App\Models\Backup;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Which release this instance runs ("dev" for source builds).
            // Rendered as the muted caption at the bottom of Settings.
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $request->user() ? [
                    'id'           => $request->user()->id,
                    'name'         => $request->user()->name,
                    'email'        => $request->user()->email,
                    'avatar_color' => $request->user()->avatar_color,
                    'roles'        => $request->user()->getRoleNames(),
                ] : null,
            ],
            'flash' => [
                'success'          => $request->session()->get('success'),
                'error'            => $request->session()->get('error'),
                'profile_success'  => $request->session()->get('profile_success'),
                'password_success' => $request->session()->get('password_success'),
                // Fresh server state when an optimistic-locking check rejects a save;
                // the document editor opens a conflict dialog when this is present.
                'saveConflict'     => $request->session()->get('saveConflict'),
                // Staged SMTP connection report from a "Send test email" run;
                // rendered by SmtpTestPanel under the test button.
                'smtpTest'         => $request->session()->get('smtpTest'),
            ],
            // Persistent backup notices (admin-only): UNATTENDED runs whose result
            // wasn't emailed — mail off, or the report email failed — so a
            // dismissable banner informs the admin instead. Cleared by
            // acknowledging. Manual runs are excluded: the admin is watching and
            // gets a toast, so a persistent banner would just be noise.
            'backupNotices' => $this->backupNotices($request),
            // Slim, admin-only nudge for the Settings "Updates" tab dot: just
            // whether a newer release is available. The tab's own controller
            // ships the full status; this keeps every response light. null for
            // non-admins — the check is an admin concern, never surfaced pre-auth.
            'updateStatus' => $this->updateStatus($request),
        ];
    }

    private function updateStatus(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('admin')) {
            return null;
        }

        return ['update_available' => \App\Support\UpdateCheck::updateAvailable()];
    }

    /** @return array<int, array<string, mixed>> */
    private function backupNotices(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('admin')) {
            return [];
        }

        return Backup::query()
            ->whereNull('acknowledged_at')
            ->where('report_emailed', false)
            ->whereIn('status', ['done', 'failed'])
            // 'pre-restore' is internal bookkeeping; 'manual' and 'import' are
            // attended (the admin ran them and is watching — they get a toast).
            ->whereNotIn('trigger', ['pre-restore', 'manual', 'import'])
            ->latest('id')
            ->limit(10)
            ->get(['id', 'status', 'error', 'report_error', 'created_at'])
            ->map(fn (Backup $b) => [
                'id'           => $b->id,
                'status'       => $b->status,
                'error'        => $b->error,
                'report_error' => $b->report_error,
                'created_at'   => $b->created_at,
            ])
            ->all();
    }
}
