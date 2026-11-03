<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('the forgot and reset pages render', function () {
    $this->get('/forgot-password')->assertOk()->assertInertia(
        fn (Assert $page) => $page->component('Auth/ForgotPassword'),
    );

    $this->get('/reset-password/sometoken')->assertOk()->assertInertia(
        fn (Assert $page) => $page->component('Auth/ResetPassword')->where('token', 'sometoken'),
    );
});

test('a known user is emailed a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->post('/forgot-password', ['email' => 'ada@example.com'])
        ->assertRedirect()->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the reset email uses the app mail style, not the stock Laravel template', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->post('/forgot-password', ['email' => 'ada@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);
        expect($mail->view)->toBe('mail.reset-password');

        $html = view($mail->view, $mail->viewData)->render();
        expect($html)
            ->toContain('Reset your password')
            ->toContain('/reset-password/' . $notification->token)
            ->toContain(urlencode($user->email))
            ->toContain(config('app.name'));

        return true;
    });
});

test('an SMTP outage never surfaces as an error on the forgot form', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    // The mailer is down: the broker throws a transport error mid-send. The
    // response must stay the generic anti-enumeration redirect, not a 500;
    // the classified log diagnosis itself is covered by SmtpErrorClassifierTest.
    // Only TransportExceptionInterface is absorbed — other bugs still surface.
    Password::shouldReceive('sendResetLink')->andThrow(
        new \Symfony\Component\Mailer\Exception\TransportException('stream_socket_client(): Unable to connect to tcp://192.0.2.27:587 (Connection timed out)'),
    );

    $this->post('/forgot-password', ['email' => 'ada@example.com'])
        ->assertRedirect()->assertSessionHas('status');
});

test('an unknown email gets the same generic response and sends nothing', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertRedirect()->assertSessionHas('status');

    Notification::assertNothingSent();
});

test('a valid token resets the password', function () {
    $user  = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token'                 => $token,
        'email'                 => 'ada@example.com',
        'password'              => 'brandnewpass',
        'password_confirmation' => 'brandnewpass',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    expect(Hash::check('brandnewpass', $user->fresh()->password))->toBeTrue();
});

test('an invalid token is rejected and the password is unchanged', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $original = $user->password;

    $this->post('/reset-password', [
        'token'                 => 'not-a-real-token',
        'email'                 => 'ada@example.com',
        'password'              => 'brandnewpass',
        'password_confirmation' => 'brandnewpass',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBe($original);
});
