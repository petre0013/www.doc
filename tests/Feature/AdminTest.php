<?php

use App\Models\User;

test('non-admins are forbidden from the admin area', function () {
    login(role: 'editor');

    $this->get('/admin/users')->assertForbidden();
});

test('an admin can create a user with a role', function () {
    login();

    $this->post('/admin/users', [
        'name' => 'Casey',
        'email' => 'casey@example.com',
        'password' => 'supersecret',
        'password_confirmation' => 'supersecret',
        'role' => 'editor',
    ])->assertRedirect();

    $user = User::firstWhere('email', 'casey@example.com');
    expect($user)->not->toBeNull();
    expect($user->hasRole('editor'))->toBeTrue();
});

test('an admin can change another user\'s role', function () {
    login();
    $other = login(role: 'viewer'); // creates a viewer and acts as them
    login(); // back to an admin actor

    $this->patch("/admin/users/{$other->id}", ['role' => 'editor'])->assertRedirect();

    expect($other->fresh()->hasRole('editor'))->toBeTrue();
    expect($other->fresh()->hasRole('viewer'))->toBeFalse();
});

test('the last admin cannot be demoted', function () {
    $admin = login(); // the only admin in the system

    $this->patch("/admin/users/{$admin->id}", ['role' => 'editor'])
        ->assertSessionHas('error');

    expect($admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('an admin cannot delete their own account', function () {
    $admin = login();

    $this->delete("/admin/users/{$admin->id}")->assertSessionHas('error');

    expect(User::find($admin->id))->not->toBeNull();
});

test('the last admin cannot be deleted', function () {
    $admin = login();
    // A second non-admin user so the table isn't down to one row by accident.
    User::factory()->create();

    $this->delete("/admin/users/{$admin->id}")->assertSessionHas('error');

    expect(User::find($admin->id))->not->toBeNull();
});
