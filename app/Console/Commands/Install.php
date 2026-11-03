<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup for a production instance. Migrations create empty tables;
 * this seeds the roles and the first admin so the instance is actually usable,
 * plus an optional welcome page. Idempotent — safe to re-run.
 *
 * Values come from options or the ADMIN_* env vars; when run interactively the
 * missing ones are prompted for. Under a non-interactive `docker exec` (no TTY)
 * the prompts no-op, so a missing email/password just fails with a clear hint.
 */
class Install extends Command
{
    protected $signature = 'app:install
        {--email= : Admin email (falls back to the ADMIN_EMAIL env var)}
        {--password= : Admin password (falls back to ADMIN_PASSWORD; min 8 chars)}
        {--name= : Admin display name (falls back to ADMIN_NAME, then "Admin")}
        {--no-welcome : Do not create the welcome workspace/page}';

    protected $description = 'Create roles and the first admin (idempotent), plus an optional welcome page.';

    public function handle(): int
    {
        // 1. Roles — idempotent (firstOrCreate).
        (new RoleSeeder)->run();
        $this->info('Roles ready: admin, editor, viewer.');

        // 2. The first admin.
        $email = $this->option('email') ?: env('ADMIN_EMAIL');
        if (! $email) {
            $email = $this->ask('Admin email');
        }
        if (! $email) {
            $this->error('No admin email given. Pass --email or set ADMIN_EMAIL.');

            return self::FAILURE;
        }

        $admin = User::where('email', $email)->first();

        if ($admin) {
            // Existing account — just make sure it can administer. Never touch
            // the password of an account that already exists.
            if ($admin->hasRole('admin')) {
                $this->info("{$email} is already an admin.");
            } else {
                $admin->assignRole('admin');
                $this->info("Promoted {$email} to admin.");
            }
        } else {
            $name = $this->option('name') ?: env('ADMIN_NAME') ?: 'Admin';

            $password = $this->option('password') ?: env('ADMIN_PASSWORD');
            if (! $password) {
                $password = $this->secret('Admin password (min 8 chars)');
            }

            $data = ['name' => $name, 'email' => $email, 'password' => (string) $password];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(8)],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return self::FAILURE;
            }

            $admin = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed by the model cast
                'avatar_color' => '#7E9D72',
            ]);
            $admin->assignRole('admin');
            $this->info("Created admin {$email}.");
        }

        // 3. Welcome content — only on a genuinely fresh instance.
        if (! $this->option('no-welcome') && Workspace::count() === 0) {
            $this->seedWelcome($admin);
            $this->info('Added a "Welcome" workspace — delete it whenever you like.');
        }

        $this->info('Done. www.doc is ready.');

        return self::SUCCESS;
    }

    /** Create the starter workspace + page, attributed to the new admin. */
    private function seedWelcome(User $admin): void
    {
        // Log the admin in so the DocumentObserver stamps authorship correctly.
        Auth::login($admin);

        $workspace = Workspace::create([
            'name' => 'Welcome',
            'description' => 'A starting point — delete this workspace once your team has its own.',
            'position' => 1,
        ]);

        Document::create([
            'title' => 'Welcome to www.doc',
            'workspace_id' => $workspace->id,
            'position' => 1,
            'content' => $this->welcomeContent(),
        ]);

        Auth::logout();
    }

    /** TipTap JSON for the welcome page (the canonical content format). */
    private function welcomeContent(): array
    {
        $heading = fn (int $level, string $text) => [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
        $paragraph = fn (string $text) => [
            'type' => 'paragraph',
            'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
        ];
        $bullets = fn (array $items) => [
            'type' => 'bulletList',
            'content' => array_map(fn ($item) => [
                'type' => 'listItem',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $item]]]],
            ], $items),
        ];

        return [
            'type' => 'doc',
            'content' => [
                $heading(1, 'Welcome to www.doc 👋'),
                $paragraph('This is your team’s knowledge base. Everything here is just a starting point — edit it, or delete this whole workspace once you have your own.'),
                $heading(2, 'Getting started'),
                $bullets([
                    'Create a workspace for each broad area (a team, a product, a department). Workspaces don’t nest — use pages for structure.',
                    'Add pages inside a workspace, and nest them by dragging one onto another in the tree.',
                    'Link pages together by typing [[ and a page title — backlinks appear automatically.',
                    'Paste from Word or a screenshot straight into the editor; images are re-hosted for you.',
                    'Use the search bar up top to find anything by title or full text.',
                ]),
                $heading(2, 'Good to know'),
                $bullets([
                    'Every save is versioned — open a page’s history to view or restore an earlier version.',
                    'Roles: admins manage users and delete content, editors write, viewers read.',
                    'Deleted pages and workspaces go to Trash, where an admin can restore or purge them.',
                ]),
                $paragraph('When you’re ready, delete this workspace from its page menu and start writing. Happy documenting!'),
            ],
        ];
    }
}
