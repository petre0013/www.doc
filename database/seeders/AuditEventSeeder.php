<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class AuditEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workspaces = Workspace::all();
        $documents = Document::all();
        $users = \App\Models\User::all();

        // Seed events for workspaces
        foreach ($workspaces as $workspace) {
            $user = $users->random();
            AuditEvent::create([
                'user_id' => $user->id,
                'event' => 'workspace.created',
                'auditable_type' => Workspace::class,
                'auditable_id' => $workspace->id,
                'workspace_id' => $workspace->id,
                'context' => ['name' => $workspace->name],
                'ip' => fake()->ipv4(),
                'created_at' => $workspace->created_at,
            ]);

            if (rand(0, 1)) {
                AuditEvent::create([
                    'user_id' => $users->random()->id,
                    'event' => 'workspace.renamed',
                    'auditable_type' => Workspace::class,
                    'auditable_id' => $workspace->id,
                    'workspace_id' => $workspace->id,
                    'context' => ['from' => 'Old ' . $workspace->name, 'to' => $workspace->name],
                    'ip' => fake()->ipv4(),
                    'created_at' => $workspace->created_at->addDays(rand(1, 5)),
                ]);
            }
        }

        // Seed events for documents
        foreach ($documents as $document) {
            $user = $users->random();
            AuditEvent::create([
                'user_id' => $user->id,
                'event' => 'document.created',
                'auditable_type' => Document::class,
                'auditable_id' => $document->id,
                'workspace_id' => $document->workspace_id,
                'context' => ['title' => $document->title],
                'ip' => fake()->ipv4(),
                'created_at' => $document->created_at,
            ]);

            $updatesCount = rand(1, 4);
            for ($i = 0; $i < $updatesCount; $i++) {
                AuditEvent::create([
                    'user_id' => $users->random()->id,
                    'event' => 'document.updated',
                    'auditable_type' => Document::class,
                    'auditable_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'context' => ['title' => $document->title],
                    'ip' => fake()->ipv4(),
                    'created_at' => $document->created_at->addHours(rand(1, 48) * ($i + 1)),
                ]);
            }
            
            if (rand(0, 4) === 0) {
                AuditEvent::create([
                    'user_id' => $users->random()->id,
                    'event' => 'document.moved',
                    'auditable_type' => Document::class,
                    'auditable_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'context' => ['title' => $document->title, 'old_parent_id' => null, 'new_parent_id' => $document->parent_id],
                    'ip' => fake()->ipv4(),
                    'created_at' => $document->updated_at,
                ]);
            }
        }
    }
}
