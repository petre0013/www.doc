<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

test('the profile page renders for the signed-in user', function () {
    $user = login();

    $this->get('/settings/profile')->assertInertia(
        fn (Assert $page) => $page
            ->component('Settings/Profile')
            ->where('user.id', $user->id)
            ->where('user.email', $user->email)
    );
});

test('a user can update their name and email', function () {
    login();

    $this->patch('/settings/profile', [
        'name' => 'Renamed Person',
        'email' => 'renamed@example.com',
    ])->assertRedirect();

    expect(auth()->user()->fresh())
        ->name->toBe('Renamed Person')
        ->email->toBe('renamed@example.com');
});

test('the avatar colour persists (autosave relies on this)', function () {
    $user = login();

    $this->patch('/settings/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'avatar_color' => 'amber',
    ])->assertRedirect();

    expect($user->fresh()->avatar_color)->toBe('amber');
});

test('an unknown avatar colour is rejected', function () {
    $user = login();

    $this->patch('/settings/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'avatar_color' => 'chartreuse',
    ])->assertSessionHasErrors('avatar_color');

    expect($user->fresh()->avatar_color)->not->toBe('chartreuse');
});

test('name and email are required', function () {
    login();

    $this->patch('/settings/profile', ['name' => '', 'email' => ''])
        ->assertSessionHasErrors(['name', 'email']);
});

test('keeping your own email is allowed despite the unique rule', function () {
    $user = login();

    $this->patch('/settings/profile', [
        'name' => 'Same Email',
        'email' => $user->email,
    ])->assertRedirect()->assertSessionHasNoErrors();
});

test('email must be unique across other users', function () {
    login();
    $other = User::factory()->create(['email' => 'taken@example.com']);

    $this->patch('/settings/profile', [
        'name' => 'Collision',
        'email' => $other->email,
    ])->assertSessionHasErrors('email');
});

test('changing the password requires the correct current one', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    login($user);

    $this->patch('/settings/password', [
        'current_password' => 'wrong-password',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

test('a user can change their password with the correct current one', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    login($user);

    $this->patch('/settings/password', [
        'current_password' => 'old-password',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertRedirect();

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});
