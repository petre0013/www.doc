<?php

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('user@example.com|127.0.0.1');
    RateLimiter::clear(AuthController::emailThrottleKey('user@example.com'));
});

it('locks out after too many failed login attempts', function () {
    User::factory()->create(['email' => 'user@example.com']);

    // Five wrong-password tries are allowed (each just a credentials error)…
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    // …the sixth is throttled with a distinct "try again" message.
    $response = $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong']);
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many login attempts');
});

it('a successful login clears the rate-limit counter', function () {
    User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);

    // Four bad attempts, then a good one.
    foreach (range(1, 4) as $i) {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong']);
    }
    $this->post('/login', ['email' => 'user@example.com', 'password' => 'secret'])
        ->assertRedirect();

    expect(RateLimiter::attempts('user@example.com|127.0.0.1'))->toBe(0);
});

it('locks an account out across IPs once too many failures pile up', function () {
    User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);

    // Rotate the client IP every 4 attempts so the per-IP limit never trips —
    // what an attacker with many addresses (or a forged X-Forwarded-For) does.
    foreach (range(1, 20) as $i) {
        $ip = '203.0.113.' . intdiv($i, 4);
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        expect(session('errors')->first('email'))->not->toContain('Too many login attempts');
    }

    // The 21st, from a brand-new IP and even with the RIGHT password, is refused.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post('/login', ['email' => 'user@example.com', 'password' => 'secret'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many login attempts');
    $this->assertGuest();
});

it('a successful login also clears the per-email counter', function () {
    User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong']);
    expect(RateLimiter::attempts(AuthController::emailThrottleKey('user@example.com')))->toBe(1);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'secret'])->assertRedirect();
    expect(RateLimiter::attempts(AuthController::emailThrottleKey('user@example.com')))->toBe(0);
});
