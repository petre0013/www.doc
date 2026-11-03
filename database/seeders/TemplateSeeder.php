<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Three sample templates for local development, seeded by the dev
 * DatabaseSeeder — guarded so an instance that already has templates is never
 * re-seeded, and deliberately showcasing the editor's block nodes (callouts,
 * task lists, code blocks). A fresh production install ships with NO templates
 * (setup deliberately doesn't seed these); users create their own.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (Template::count() > 0) {
            return;
        }

        $creator = auth()->id();

        Template::create([
            'name'          => 'Runbook',
            'description'   => 'Step-by-step operational procedure with prerequisites, commands and a rollback plan.',
            'content'       => $this->runbook(),
            'created_by_id' => $creator,
        ]);

        Template::create([
            'name'          => 'Meeting notes',
            'description'   => 'Agenda, discussion notes, decisions and action items for a recurring meeting.',
            'content'       => $this->meetingNotes(),
            'created_by_id' => $creator,
        ]);

        Template::create([
            'name'          => 'RFC',
            'description'   => 'Propose a change: motivation, design, alternatives considered and open questions.',
            'content'       => $this->rfc(),
            'created_by_id' => $creator,
        ]);
    }

    // ── Node helpers (same JSON shapes the editor persists) ────────────────────

    private function h(int $level, string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function bullets(array $items): array
    {
        return [
            'type'    => 'bulletList',
            'content' => array_map(fn (string $t) => [
                'type'    => 'listItem',
                'content' => [$this->p($t)],
            ], $items),
        ];
    }

    private function tasks(array $items): array
    {
        return [
            'type'    => 'taskList',
            'content' => array_map(fn (string $t) => [
                'type'    => 'taskItem',
                'attrs'   => ['checked' => false],
                'content' => [$this->p($t)],
            ], $items),
        ];
    }

    private function callout(string $kind, string $text): array
    {
        return ['type' => 'callout', 'attrs' => ['kind' => $kind], 'content' => [$this->p($text)]];
    }

    private function code(string $language, string $text): array
    {
        return ['type' => 'codeBlock', 'attrs' => ['language' => $language], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    // ── Template bodies ─────────────────────────────────────────────────────────

    private function runbook(): array
    {
        return ['type' => 'doc', 'content' => [
            $this->callout('info', 'What this runbook covers, who should use it, and when. Link the service or system page it belongs to.'),

            $this->h(2, 'Prerequisites'),
            $this->tasks([
                'Access to the target system (VPN, SSH key, admin account…)',
                'A recent backup exists and is restorable',
                'Stakeholders notified if the procedure causes downtime',
            ]),

            $this->h(2, 'Procedure'),
            $this->p('Number the steps. Keep one command per block so it can be copied as-is.'),
            $this->code('bash', "# 1. Check the current state\nsystemctl status my-service\n\n# 2. Apply the change\n…"),
            $this->callout('warning', 'Call out the point of no return — the step after which rolling back means restoring from backup.'),

            $this->h(2, 'Verification'),
            $this->tasks([
                'Service reports healthy',
                'Key user flow works end-to-end',
                'No new errors in the logs after 15 minutes',
            ]),

            $this->h(2, 'Rollback'),
            $this->p('How to undo the change if verification fails.'),
        ]];
    }

    private function meetingNotes(): array
    {
        return ['type' => 'doc', 'content' => [
            $this->p('Date: …   ·   Attendees: …   ·   Facilitator: …'),

            $this->h(2, 'Agenda'),
            $this->bullets([
                'Topic one',
                'Topic two',
            ]),

            $this->h(2, 'Notes'),
            $this->p('Discussion points, context, and anything worth remembering later.'),

            $this->h(2, 'Decisions'),
            $this->callout('success', 'Record each decision and who made it — this is the part people come back for.'),

            $this->h(2, 'Action items'),
            $this->tasks([
                'Action — owner — due date',
                'Action — owner — due date',
            ]),
        ]];
    }

    private function rfc(): array
    {
        return ['type' => 'doc', 'content' => [
            $this->callout('info', 'Status: Draft   ·   Author: …   ·   Reviewers: …'),

            $this->h(2, 'Summary'),
            $this->p('One paragraph: what is proposed and why it matters.'),

            $this->h(2, 'Motivation'),
            $this->p('The problem being solved. What happens if we do nothing?'),

            $this->h(2, 'Proposal'),
            $this->p('The design, in enough detail that someone else could implement it.'),

            $this->h(2, 'Alternatives considered'),
            $this->bullets([
                'Alternative A — why not',
                'Alternative B — why not',
            ]),

            $this->h(2, 'Risks'),
            $this->callout('warning', 'Migration cost, failure modes, security or compliance impact.'),

            $this->h(2, 'Open questions'),
            $this->tasks([
                'Unresolved question one',
                'Unresolved question two',
            ]),
        ]];
    }
}
