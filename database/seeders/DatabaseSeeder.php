<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles first, then the demo accounts that get assigned to them.
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
        ]);

        // Log the primary admin in so seeded pages and their version snapshots
        // are attributed to a real user (the observer stamps Auth::id()).
        auth()->login(User::where('email', 'admin@example.com')->firstOrFail());

        $this->call(WorkspaceSeeder::class);
        $this->call(TemplateSeeder::class);
        $this->call(LargePageSeeder::class);
        $this->call(AuditEventSeeder::class);

        // High-volume factory data on top of the curated content. Runs last: it
        // reuses WorkspaceSeeder's groups/tags and logs out when done.
        $this->call(BulkDataSeeder::class);
    }
}
