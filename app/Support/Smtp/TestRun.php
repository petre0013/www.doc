<?php

namespace App\Support\Smtp;

use Illuminate\Http\RedirectResponse;

/**
 * Shared orchestration for the "Send test email" endpoints (admin Email tab,
 * setup wizard, Backups tab): run the staged probe with the real send as its
 * final stage, then redirect back flashing the `smtpTest` report (rendered by
 * SmtpTestPanel) plus a success or error toast. One place, so the three
 * endpoints cannot drift.
 *
 * Container-resolved so tests can swap the probe (see fakeSmtpProbe() in
 * tests/Pest.php).
 */
class TestRun
{
    public function __construct(private SmtpProbe $probe)
    {
    }

    /**
     * @param  array  $mail  decrypted transport config (host/port/encryption read)
     * @param  callable  $send  performs the real test send; throws on failure
     * @param  string  $successMessage  toast for the all-green case
     * @param  array  $extra  additional report fields (e.g. the backups
     *         endpoint's `transport` label)
     * @param  callable|null  $onResult  invoked with the boolean pass/fail before
     *         redirecting, so callers can persist the outcome (see TestStatus)
     */
    public function flash(array $mail, callable $send, string $successMessage, array $extra = [], ?callable $onResult = null): RedirectResponse
    {
        $stages = $this->probe->run(
            (string) ($mail['host'] ?? ''),
            (int) ($mail['port'] ?? 0),
            (string) ($mail['encryption'] ?? 'tls'),
            $send,
            (bool) ($mail['verify_peer'] ?? true),
        );

        $report = [
            'stages'   => $stages,
            'endpoint' => ($mail['host'] ?? '') . ':' . (int) ($mail['port'] ?? 0),
            ...$extra,
        ];

        $ok = ! SmtpProbe::failed($stages);
        if ($onResult) {
            $onResult($ok);
        }

        if (! $ok) {
            return back()
                ->with('error', 'The test email could not be sent — see the connection check for the failing step.')
                ->with('smtpTest', $report);
        }

        return back()
            ->with('success', $successMessage)
            ->with('smtpTest', $report);
    }
}
