<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Setting;
use App\Services\Backup\BackupNotifier;
use App\Services\Backup\Destinations\DestinationFactory;
use App\Support\Audit;
use App\Support\BackupSettings;
use App\Support\Smtp\TestRun;
use App\Support\MailSettings;
use App\Support\TestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin-only Backups tab: configure the cadence + destination (private disk or
 * SMB share) + email notifications, run a backup now, and download / restore /
 * delete archives. Heavy work is queued (RunBackupJob / RestoreBackupJob); the
 * page polls for in-flight status.
 *
 * Secrets (SMB + SMTP passwords) live ENCRYPTED in the `backup` setting blob and
 * are never sent to the frontend — see BackupSettings::forDisplay().
 */
class BackupController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Backup::class);

        $backups = Backup::with('creator:id,name')
            ->latest('id')
            ->get()
            ->map(fn (Backup $b) => [
                'id'          => $b->id,
                'status'      => $b->status,
                'trigger'     => $b->trigger,
                'disk'        => $b->disk,
                'size_bytes'  => $b->size_bytes,
                'error'       => $b->error,
                'created_at'  => $b->created_at,
                'finished_at' => $b->finished_at,
                'created_by'     => $b->creator?->name,
                'counts'         => $b->manifest['counts'] ?? null,
                'encrypted'      => $b->manifest['encryption']['enabled'] ?? false,
                // An imported archive we couldn't decrypt (wrong/absent key): listed
                // for the record, but not restorable — the UI warns and disables it.
                'undecryptable'  => $b->manifest['encryption']['undecryptable'] ?? false,
                'key_mismatch'   => ($b->manifest['encryption']['enabled'] ?? false)
                                    && ($b->manifest['encryption']['fingerprint'] ?? null)
                                    && ($b->manifest['encryption']['fingerprint'] !== \App\Services\Backup\ArchiveCipher::currentFingerprint()),
                'restore_status' => $b->restore_status,
                'restore_error'  => $b->restore_error,
            ]);

        return Inertia::render('Settings/Backups', [
            'backups'   => $backups,
            'settings'  => BackupSettings::forDisplay(),
            'intervals' => array_keys(config('backup.intervals')),
            'drivers'   => config('backup.drivers'),
            'destinationTest' => TestStatus::get('backup_destination'),
            'emailTest'       => TestStatus::get('backup_email'),
        ]);
    }

    /** Save the backup cadence / destination / mail settings (passwords preserved). */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        $validated = $request->validate([
            'enabled'   => ['required', 'boolean'],
            // A preset cadence key (daily/2days/weekly) OR a custom interval given
            // directly in hours (1..8760 = up to a year). RunScheduledBackup
            // resolves either to a number of hours.
            'interval'  => ['required', function ($attr, $value, $fail) {
                if (in_array($value, array_keys(config('backup.intervals')), true)) {
                    return;
                }
                if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 8760) {
                    $fail('Choose a preset frequency or a custom interval of 1–8760 hours.');
                }
            }],
            // 0 = never prune (keep every backup); 1..365 = keep that many newest.
            'retention' => ['required', 'integer', 'min:0', 'max:365'],
            'driver'    => ['required', Rule::in(config('backup.drivers'))],

            // Encrypt archives at rest — only allowed once a valid 32-byte base64 key exists in env.
            'encryption' => ['required', 'boolean', function ($attr, $value, $fail) {
                if ($value && ! \App\Services\Backup\ArchiveCipher::configured()) {
                    $fail('The BACKUP_ENCRYPTION_KEY in your environment is missing or invalid (must be a 32-byte base64 string).');
                }
            }],

            // host + share are required only when SMB is the chosen driver.
            'smb.host'     => [Rule::requiredIf(fn () => $request->input('driver') === 'smb'), 'nullable', 'string', 'max:255'],
            'smb.share'    => [Rule::requiredIf(fn () => $request->input('driver') === 'smb'), 'nullable', 'string', 'max:255'],
            'smb.path'     => ['nullable', 'string', 'max:1024'],
            'smb.username' => ['nullable', 'string', 'max:255'],
            'smb.password' => ['nullable', 'string', 'max:1024'],
            'smb.domain'   => ['nullable', 'string', 'max:255'],

            'mail.enabled'      => ['required', 'boolean'],
            'mail.to'           => ['nullable', 'email', 'max:255'],
            'mail.host'         => ['nullable', 'string', 'max:255'],
            'mail.port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail.username'     => ['nullable', 'string', 'max:255'],
            'mail.password'     => ['nullable', 'string', 'max:1024'],
            'mail.encryption'   => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'mail.from_address' => ['nullable', 'email', 'max:255'],
            'mail.from_name'    => ['nullable', 'string', 'max:255'],
        ]);

        $this->assertMailAuthPaired($request);

        Setting::put('backup', $this->mergeSettings($validated));

        // A destination/mail change makes the last connection test stale — clear
        // both so the passive badges read "not tested yet" until re-checked.
        TestStatus::clear('backup_destination');
        TestStatus::clear('backup_email');

        // Operational knobs only — SMB/SMTP credentials never enter the trail.
        Audit::record('settings.backup_updated', null, [
            'enabled'    => (bool) $validated['enabled'],
            'interval'   => $validated['interval'],
            'retention'  => (int) $validated['retention'],
            'driver'     => $validated['driver'],
            'encryption' => (bool) $validated['encryption'],
        ]);

        return back()->with('success', 'Backup settings saved.');
    }

    /** Dismiss a backup's in-app notice banner. */
    public function acknowledge(Backup $backup): RedirectResponse
    {
        $this->authorize('view', $backup);

        $backup->update(['acknowledged_at' => now()]);

        return back();
    }

    /** Kick off a manual backup now. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        $backup = Backup::create([
            'trigger'       => 'manual',
            'disk'          => BackupSettings::get()['driver'] ?? 'local',
            'status'        => 'pending',
            // Actor + address for the completion event the queue job writes:
            // it has no session and no request of its own.
            'created_by_id' => $request->user()->id,
            'ip'            => $request->ip(),
        ]);

        RunBackupJob::dispatch($backup->id);

        // The human action is the request; the queued job logs the completion.
        Audit::record('backup.requested', $backup);

        // No flash: the in-progress modal signals the start, and the page toasts
        // once on completion. A "started" flash here would double up.
        return back();
    }

    /**
     * Register an UPLOADED archive into the list so it can be restored later. If
     * it's encrypted, an optional key is tried; a wrong/absent key still imports
     * it (flagged undecryptable). Heavy I/O runs in ImportBackupJob — the page
     * polls the row, same as a normal backup.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        $validated = $request->validate([
            // 1 GB ceiling — kept in step with the app/web upload limits
            // (docker/app/php.prod.ini + docker/nginx/default.conf). Backups are
            // the largest legitimate upload, so those limits are raised for this route.
            'file' => ['required', 'file', 'max:1048576'],
            'key'  => ['nullable', 'string'],
        ], [
            'file.required' => 'Choose a backup archive to import.',
            // Fired when PHP rejects the upload (exceeds upload_max_filesize /
            // post_max_size) — a far more useful hint than "the file failed to upload".
            'file.uploaded' => 'The archive could not be uploaded — it likely exceeds the server’s upload size limit. Ask an administrator to raise it, or import a smaller archive.',
            'file.max'      => 'The archive is larger than the 1 GB import limit.',
        ]);

        // Stage the upload where the queue worker can read it. Worker + web share
        // the app-storage volume in prod (same constraint as scheduled backups).
        $staged = $request->file('file')->store('backups/imports', 'local');
        $stagingPath = Storage::disk('local')->path($staged);

        $backup = Backup::create([
            'trigger'       => 'import',
            'disk'          => BackupSettings::get()['driver'] ?? 'local',
            'status'        => 'pending',
            'created_by_id' => $request->user()->id,
            'ip'            => $request->ip(),
        ]);

        ImportBackupJob::dispatch($backup->id, $stagingPath, $validated['key'] ?? null);

        Audit::record('backup.import_requested', $backup, [
            'filename' => $request->file('file')->getClientOriginalName(),
        ]);

        return back();
    }

    /** Poll a single backup's status (for the in-progress spinner). */
    public function show(Backup $backup): JsonResponse
    {
        $this->authorize('view', $backup);

        return response()->json([
            'id'     => $backup->id,
            'status' => $backup->status,
            'error'  => $backup->error,
        ]);
    }

    public function download(Backup $backup): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $backup);

        abort_unless($backup->status === 'done' && $backup->path, 404);

        if (!DestinationFactory::make($backup->disk)->exists($backup->path)) {
            $backup->update(['status' => 'missing', 'error' => 'The archive was missing from the destination.']);
            return back()->with('error', 'The backup archive could not be found on the destination disk.');
        }

        // Pull from wherever it lives (local disk or SMB share) to a temp file
        // and stream that, cleaning it up after send.
        $temp = DestinationFactory::make($backup->disk)->fetch($backup->path);

        return response()->download($temp, basename($backup->path))->deleteFileAfterSend();
    }

    public function restore(Backup $backup): RedirectResponse
    {
        $this->authorize('restore', $backup);

        if ($backup->path && !DestinationFactory::make($backup->disk)->exists($backup->path)) {
            $backup->update(['status' => 'missing', 'error' => 'The archive was missing from the destination.']);
            return back()->with('error', 'The backup archive could not be found on the destination disk. It may have been deleted outside the application.');
        }

        // An imported archive we couldn't decrypt has no readable canonical layer.
        if ($backup->manifest['encryption']['undecryptable'] ?? false) {
            return back()->with('error', 'This imported archive could not be decrypted (the key was wrong or missing), so it cannot be restored.');
        }

        if ($backup->manifest['encryption']['enabled'] ?? false) {
            $fingerprint = $backup->manifest['encryption']['fingerprint'] ?? null;
            if ($fingerprint && $fingerprint !== \App\Services\Backup\ArchiveCipher::currentFingerprint()) {
                return back()->with('error', 'The BACKUP_ENCRYPTION_KEY currently configured does not match the key used to encrypt this archive. You cannot restore it.');
            }
        }

        // Mark restoring synchronously so the UI's progress modal shows at once;
        // the job flips it to restored/failed and the page toasts the outcome.
        $backup->update(['restore_status' => 'restoring', 'restore_error' => null, 'restored_at' => null]);
        RestoreBackupJob::dispatch($backup->id);

        Audit::record('backup.restore_requested', $backup);

        return back();
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->authorize('delete', $backup);

        if ($backup->path) {
            DestinationFactory::make($backup->disk)->delete($backup->path);
        }
        $backup->delete();

        Audit::record('backup.deleted', null, [
            'backup_id' => $backup->id,
            'trigger'   => $backup->trigger,
            'path'      => $backup->path,
        ]);

        return back()->with('success', 'Backup deleted.');
    }

    /** Verify the configured destination is reachable and writable (probe file). */
    public function testDestination(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        $driver = $request->input('driver', 'local');

        try {
            if ($driver === 'smb') {
                $smb = $this->resolveSmbForTest($request);
                DestinationFactory::smb($smb)->test();
            } else {
                DestinationFactory::make('local')->test();
            }
        } catch (\Throwable $e) {
            TestStatus::record('backup_destination', false, $e->getMessage());

            return back()->with('error', $e->getMessage());
        }

        TestStatus::record('backup_destination', true);

        return back()->with('success', 'Destination is reachable and writable.');
    }

    /** Send a test email using the (possibly unsaved) mail settings in the request. */
    public function testEmail(Request $request, BackupNotifier $notifier, TestRun $test): RedirectResponse
    {
        $this->authorize('create', Backup::class);

        // Host/port are required only when there's no global SMTP to fall back
        // on — leaving them blank means "use the global Email settings".
        $needsOwnSmtp = ! MailSettings::isConfigured();
        $request->validate([
            'mail.to'         => ['required', 'email'],
            'mail.host'       => [Rule::requiredIf($needsOwnSmtp), 'nullable', 'string'],
            'mail.port'       => [Rule::requiredIf($needsOwnSmtp), 'nullable', 'integer', 'min:1', 'max:65535'],
            'mail.encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
        ]);

        $this->assertMailAuthPaired($request);

        // Staged connection check against the EFFECTIVE transport (the backup
        // block's own SMTP, or the global one it falls back to). The report's
        // `transport` label names which one was tested, so a diagnosis is
        // never pinned to the wrong server's config.
        $mail = $this->resolveMailForTest($request);

        return $test->flash(
            $mail,
            fn () => $notifier->sendTest($mail),
            'Test email sent to ' . $request->input('mail.to') . '.',
            ['transport' => trim((string) $request->input('mail.host', '')) === '' ? 'global' : 'own'],
            fn (bool $ok) => TestStatus::record('backup_email', $ok),
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * SMTP auth is all-or-nothing: a username needs a password and vice-versa.
     * A password counts as present when typed OR already saved (blank = keep
     * stored), so editing the username on an authenticated account doesn't fail.
     */
    private function assertMailAuthPaired(Request $request): void
    {
        $username    = trim((string) $request->input('mail.username', ''));
        $typedPw     = (string) $request->input('mail.password', '') !== '';
        $savedPw     = ! empty(BackupSettings::get()['mail']['password'] ?? '');
        $passPresent = $typedPw || $savedPw;

        $errors = [];
        if ($username !== '' && ! $passPresent) {
            $errors['mail.password'] = 'Enter a password to go with the username, or clear the username.';
        }
        if ($username === '' && $typedPw) {
            $errors['mail.username'] = 'Enter a username to go with the password.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Merge the submitted settings over the stored ones, re-encrypting changed
     * passwords and KEEPING the existing encrypted password when the field comes
     * back blank (the UI never echoes a saved password).
     */
    private function mergeSettings(array $validated): array
    {
        $current = BackupSettings::get();

        // Only overwrite SMB settings if the user is actively configuring the SMB driver.
        if (($validated['driver'] ?? 'local') === 'smb') {
            $smb = array_merge($current['smb'], array_filter(
                $validated['smb'] ?? [],
                fn ($k) => $k !== 'password',
                ARRAY_FILTER_USE_KEY,
            ));
            $smb['password'] = $this->keepOrEncrypt($validated['smb']['password'] ?? '', $current['smb']['password'] ?? '');
        } else {
            $smb = $current['smb'];
        }

        // Only overwrite Mail settings if mail notifications are being enabled/configured.
        if (!empty($validated['mail']['enabled'])) {
            $mail = array_merge($current['mail'], array_filter(
                $validated['mail'] ?? [],
                fn ($k) => $k !== 'password',
                ARRAY_FILTER_USE_KEY,
            ));
            $mail['password'] = $this->keepOrEncrypt($validated['mail']['password'] ?? '', $current['mail']['password'] ?? '');
        } else {
            $mail = $current['mail'];
            $mail['enabled'] = false;
        }

        return [
            'enabled'    => (bool) $validated['enabled'],
            // Custom intervals come in as a numeric (hours) string — store as int;
            // preset keys stay strings.
            'interval'   => is_numeric($validated['interval']) ? (int) $validated['interval'] : $validated['interval'],
            'retention'  => (int) $validated['retention'],
            'driver'     => $validated['driver'],
            'encryption' => (bool) $validated['encryption'],
            'smb'        => $smb,
            'mail'       => $mail,
        ];
    }

    /** Blank submitted password → keep stored cipher; otherwise encrypt the new one. */
    private function keepOrEncrypt(string $submitted, string $storedCipher): string
    {
        return $submitted === '' ? $storedCipher : BackupSettings::encrypt($submitted);
    }

    /** SMB config for a test: use the typed password, or fall back to the saved one. */
    private function resolveSmbForTest(Request $request): array
    {
        $current = BackupSettings::get();
        $smb     = array_merge($current['smb'], $request->input('smb', []));
        $smb['password'] = ($request->input('smb.password') ?: '') !== ''
            ? $request->input('smb.password')
            : BackupSettings::smbPassword();

        return $smb;
    }

    /** Mail config for a test: use the typed password, or fall back to the saved one. */
    private function resolveMailForTest(Request $request): array
    {
        $current = BackupSettings::get();
        $mail    = array_merge($current['mail'], $request->input('mail', []));
        $mail['password'] = ($request->input('mail.password') ?: '') !== ''
            ? $request->input('mail.password')
            : BackupSettings::mailPassword();

        // No backup-specific host → test through the global SMTP, same as a real
        // report would (see BackupSettings::mailConfig).
        return BackupSettings::applyGlobalMailFallback($mail);
    }
}
