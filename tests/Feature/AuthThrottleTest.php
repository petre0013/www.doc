<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('user@example.com|127.0.0.1');
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
