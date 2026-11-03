<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts spanning every role. All share the password "password".
 * Runs after RoleSeeder (roles must exist) and before WorkspaceSeeder so the
 * seeded content can be attributed to a spread of these authors.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // [name, email, role, avatar_color]
        $users = [
            // Admins — full access, including the admin panel.
            ['Admin User',   'admin@example.com', 'admin',  'sage'],
            ['Diana Flores', 'diana@example.com', 'admin',  'slate'],

            // Editors — create and edit documents.
            ['Ethan Wright', 'ethan@example.com', 'editor', 'sky'],
            ['Maya Chen',    'maya@example.com',  'editor', 'amber'],
            ['Omar Haddad',  'omar@example.com',  'editor', 'purple'],
            ['Lucía Romero', 'lucia@example.com', 'editor', 'rose'],

            // Viewers — read-only.
            ['Vince Park',   'vince@example.com', 'viewer', 'slate'],
            ['Nina Patel',   'nina@example.com',  'viewer', 'sage'],
            ['Sam Okafor',   'sam@example.com',   'viewer', 'sky'],
        ];

        foreach ($users as [$name, $email, $role, $color]) {
            $user = User::factory()->create([
                'name'         => $name,
                'email'        => $email,
                'avatar_color' => $color,
            ]);

            $user->syncRoles($role);
        }

        $this->command->info('Seeded '.count($users).' users (2 admins, 4 editors, 3 viewers) — password: "password".');
    }
}
