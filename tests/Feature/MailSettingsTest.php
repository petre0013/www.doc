<?php

use App\Mail\TestMail;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/** A full, valid SMTP payload with optional overrides. */
function mailPayload(array $overrides = []): array
{
    return array_replace([
        'host'         => 'smtp.acme.test',
        'port'         => 587,
        'encryption'   => 'tls',
        'username'     => 'mailer',
        'password'     => 'secret',
        'from_address' => 'docs@acme.test',
        'from_name'    => 'Acme Docs',
    ], $overrides);
}

test('non-admins cannot reach the email settings', function () {
    login(null, 'editor');

    $this->get('/admin/settings/mail')->assertForbidden();
    $this->patch('/admin/settings/mail', mailPayload())->assertForbidden();
});

test('an admin can view and save email settings', function () {
    login();

    $this->get('/admin/settings/mail')->assertOk()->assertInertia(
        fn (Assert $page) => $page->component('Settings/Mail')->has('settings'),
    );

    $this->patch('/admin/settings/mail', mailPayload(['port' => 2525, 'encryption' => 'ssl']))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(MailSettings::get()['host'])->toBe('smtp.acme.test')
        ->and(MailSettings::get()['port'])->toBe(2525)
        ->and(MailSettings::password())->toBe('secret');
});

test('a blank password preserves the stored one', function () {
    login();
    MailSettings::save(mailPayload(['password' => 'original']));

    $this->patch('/admin/settings/mail', mailPayload(['password' => '']))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(MailSettings::password())->toBe('original');
});

test('forDisplay never leaks the SMTP password', function () {
    MailSettings::save(mailPayload(['password' => 'topsecret']));

    $display = MailSettings::forDisplay();

    expect($display)->not->toHaveKey('password')
        ->and($display['password_set'])->toBeTrue()
        ->and($display['host'])->toBe('smtp.acme.test');
});

test('configured SMTP settings drive the default mailer', function () {
    MailSettings::save(mailPayload(['port' => 2525, 'encryption' => 'ssl']));

    MailSettings::applyToMailer();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.acme.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.mailers.smtp.encryption'))->toBe('ssl')
        ->and(config('mail.from.address'))->toBe('docs@acme.test');
});

test('certificate verification defaults to on and can be disabled', function () {
    login();

    // Default: on — and the mailer config says so.
    MailSettings::save(mailPayload());
    MailSettings::applyToMailer();
    expect(MailSettings::get()['verify_peer'])->toBeTrue()
        ->and(config('mail.mailers.smtp.verify_peer'))->toBeTrue();

    // Disabled via the settings form: persists, reaches the mailer config
    // (Symfony's EsmtpTransportFactory reads it), and lands in the audit trail.
    $this->patch('/admin/settings/mail', mailPayload(['verify_peer' => false]))
        ->assertRedirect()->assertSessionHasNoErrors();

    MailSettings::applyToMailer();

    expect(MailSettings::get()['verify_peer'])->toBeFalse()
        ->and(config('mail.mailers.smtp.verify_peer'))->toBeFalse()
        ->and(MailSettings::forDisplay()['verify_peer'])->toBeFalse();

    $event = \App\Models\AuditEvent::where('event', 'settings.mail_updated')->latest('id')->first();
    expect($event->context['verify_peer'])->toBeFalse();
});

test('an unconfigured mailer is left untouched', function () {
    config(['mail.default' => 'log']);

    MailSettings::applyToMailer(); // no host set → no-op

    expect(config('mail.default'))->toBe('log');
});

test('the test-email endpoint sends through the entered settings', function () {
    login();
    Mail::fake();
    fakeSmtpProbe();

    $this->post('/admin/settings/mail/test', mailPayload(['to' => 'me@acme.test']))
        ->assertRedirect()->assertSessionHas('success')
        // The staged connection report rides along for the inline panel.
        ->assertSessionHas('smtpTest', fn ($r) => count($r['stages']) === 4 && $r['endpoint'] === 'smtp.acme.test:587');

    Mail::assertSent(TestMail::class);
});

test('a failing probe flashes an error and the stage report, and skips the send', function () {
    login();
    Mail::fake();

    // A probe that died at the TCP stage: the send stage must be skipped and
    // the response must carry the report for the panel plus an error toast.
    fakeSmtpProbe([
        ['stage' => 'dns', 'status' => 'skipped', 'detail' => 'IP address'],
        ['stage' => 'connect', 'status' => 'failed', 'detail' => 'TCP connection to 192.0.2.27:587 failed: connection timed out'],
        ['stage' => 'tls', 'status' => 'skipped', 'detail' => 'Not attempted'],
        ['stage' => 'send', 'status' => 'skipped', 'detail' => 'Not attempted'],
    ]);

    $this->post('/admin/settings/mail/test', mailPayload(['host' => '192.0.2.27', 'to' => 'me@acme.test']))
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionHas('smtpTest', fn ($r) => $r['stages'][1]['status'] === 'failed');

    Mail::assertNothingSent();
});

test('a passing test records the mail status and the page exposes it', function () {
    login();
    Mail::fake();
    fakeSmtpProbe();

    $this->post('/admin/settings/mail/test', mailPayload(['to' => 'me@acme.test']))
        ->assertRedirect()->assertSessionHas('success');

    $status = \App\Support\TestStatus::get('mail');
    expect($status['ok'])->toBeTrue()->and($status['at'])->not->toBeNull();

    // The Email page surfaces it for the passive status badge.
    $this->get('/admin/settings/mail')->assertInertia(
        fn (Assert $page) => $page->where('testStatus.ok', true),
    );
});

test('a failing test records a failed mail status', function () {
    login();
    Mail::fake();
    fakeSmtpProbe([
        ['stage' => 'dns', 'status' => 'skipped', 'detail' => 'IP address'],
        ['stage' => 'connect', 'status' => 'failed', 'detail' => 'timed out'],
        ['stage' => 'tls', 'status' => 'skipped', 'detail' => 'Not attempted'],
        ['stage' => 'send', 'status' => 'skipped', 'detail' => 'Not attempted'],
    ]);

    $this->post('/admin/settings/mail/test', mailPayload(['host' => '192.0.2.27', 'to' => 'me@acme.test']))
        ->assertRedirect()->assertSessionHas('error');

    expect(\App\Support\TestStatus::get('mail')['ok'])->toBeFalse();
});

test('saving mail settings clears a prior test status', function () {
    login();
    \App\Support\TestStatus::record('mail', true);

    $this->patch('/admin/settings/mail', mailPayload())
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(\App\Support\TestStatus::get('mail'))->toBeNull();
});
