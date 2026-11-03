<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Models\Document;
use App\Models\Tag;
use App\Support\Audit;
use Database\Seeders\Concerns\BuildsTipTapContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkspaceSeeder extends Seeder
{
    /** The page-content DSL (`buildContent`, `heading`, `diagram`, …). */
    use BuildsTipTapContent;

    public function run(): void
    {
        $admin = User::first() ?? User::factory()->create([
            'name'     => 'Admin User',
            'email'    => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        // Pages are attributed to a spread of content authors (admins + editors);
        // viewers never author. Falls back to the admin if no roles are seeded.
        $authorIds = User::role(['admin', 'editor'])->pluck('id')->all() ?: [$admin->id];

        // ── Tags ──────────────────────────────────────────────────────────────
        $tags = [
            'guide'      => Tag::firstOrCreate(['name' => 'Guide']),
            'style'      => Tag::firstOrCreate(['name' => 'Style']),
            'design'     => Tag::firstOrCreate(['name' => 'Design']),
            'setup'      => Tag::firstOrCreate(['name' => 'Setup']),
            'network'    => Tag::firstOrCreate(['name' => 'Network']),
            'security'   => Tag::firstOrCreate(['name' => 'Security']),
            'ops'        => Tag::firstOrCreate(['name' => 'Ops']),
            'policy'     => Tag::firstOrCreate(['name' => 'Policy']),
            'compliance' => Tag::firstOrCreate(['name' => 'Compliance']),
            'incident'   => Tag::firstOrCreate(['name' => 'Incident']),
            'support'    => Tag::firstOrCreate(['name' => 'Support']),
            'sales'      => Tag::firstOrCreate(['name' => 'Sales']),
            'marketing'  => Tag::firstOrCreate(['name' => 'Marketing']),
            'finance'    => Tag::firstOrCreate(['name' => 'Finance']),
            'legal'      => Tag::firstOrCreate(['name' => 'Legal']),
            'demo'       => Tag::firstOrCreate(['name' => 'Demo']),
        ];

        // ── Workspaces ────────────────────────────────────────────────────────
        $workspacesData = [

            // ── 1. Engineering Hub ────────────────────────────────────────────
            [
                'name'        => 'Engineering Hub',
                'description' => 'Coding guidelines, dev setup, architecture and deployment procedures.',
                'position'    => 1,
                'pages'       => [
                    [
                        'title'    => 'Getting Started',
                        'position' => 1,
                        'tags'     => ['guide'],
                        'content'  => [
                            'Welcome to the Engineering Hub. Follow the steps below to get your local environment running and to understand our conventions.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/devsetup/900/420', 'alt' => 'Development environment overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Recommended reading order'],
                            ['type' => 'orderedList', 'items' => [
                                '[[Local Environment Setup]] — Docker, dependencies and `.env`',
                                '[[IDE Settings]] — plugins and format-on-save config',
                                '[[PHP & Laravel Style]] and [[React & Tailwind Style]] guides',
                                '[[Deployment Guide]] — CI pipeline and release process',
                            ]],
                            'Once your environment is running, consult the [[Architecture Overview]] to understand how the layers fit together.',
                        ],
                        'children' => [
                            [
                                'title'    => 'Local Environment Setup',
                                'position' => 1,
                                'tags'     => ['setup'],
                                'content'  => [
                                    'Setting up your local environment takes about 15 minutes. You will need Docker, PHP 8.3 and Node 20 installed on your host.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Step-by-step'],
                                    ['type' => 'orderedList', 'items' => [
                                        'Clone the repository: `git clone git@github.com:org/project.git`',
                                        'Copy environment file: `cp .env.example .env`',
                                        'Install PHP dependencies: `composer install`',
                                        'Install JS dependencies: `npm install`',
                                        'Generate app key: `php artisan key:generate`',
                                        'Run migrations and seed: `php artisan migrate --seed`',
                                        'Start Vite: `npm run dev`',
                                    ]],
                                    ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                        "git clone git@github.com:org/project.git\ncd project\ncp .env.example .env\ncomposer install && npm install\nphp artisan key:generate\nphp artisan migrate --seed\nnpm run dev",
                                    ],
                                    ['type' => 'blockquote', 'text' => 'Tip: run `php artisan horizon` in a separate terminal to process queued jobs (exports, imports).'],
                                    'Your editor setup is covered in [[IDE Settings]]. Coding conventions live in [[PHP & Laravel Style]] and [[React & Tailwind Style]].',
                                ],
                            ],
                            [
                                'title'    => 'IDE Settings',
                                'position' => 2,
                                'tags'     => ['setup'],
                                'content'  => [
                                    'We recommend VS Code or PhpStorm. Both editors support the full Laravel + React + Tailwind toolchain out of the box.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'VS Code extensions'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Prettier — Opinionated Code Formatter',
                                        'ESLint — JavaScript linting',
                                        'PHP Intelephense — language server',
                                        'Tailwind CSS IntelliSense — class autocomplete',
                                        'Laravel Blade Snippets',
                                    ]],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Recommended settings.json'],
                                    ['type' => 'codeBlock', 'language' => 'json', 'code' =>
                                        "{\n  \"editor.formatOnSave\": true,\n  \"editor.defaultFormatter\": \"esbenp.prettier-vscode\",\n  \"[php]\": { \"editor.defaultFormatter\": \"bmewburn.vscode-intelephense-client\" },\n  \"tailwindCSS.experimental.classRegex\": [[\"cn\\\\(([^)]*)\\\\)\", \"'([^']*)'\"]]  \n}",
                                    ],
                                    'Enable format-on-save for consistent styling, matching our [[PHP & Laravel Style]] rules.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Architecture Overview',
                        'position' => 2,
                        'content'  => [
                            'Our system is a standard Laravel + Inertia.js + React SPA. The backend owns all data and business logic; the frontend handles rendering and user interaction.',
                            ['type' => 'diagram', 'name' => 'Request Lifecycle',
                                'settings' => ['routing' => 'step', 'snap' => false],
                                'nodes' => [
                                    ['id' => 'browser', 'label' => 'Browser',    'kind' => 'workstation', 'color' => 'blue',       'x' => 0,   'y' => 80],
                                    ['id' => 'nginx',   'label' => 'Nginx',      'kind' => 'router',      'color' => 'sage',       'x' => 180, 'y' => 80],
                                    ['id' => 'app',     'label' => 'Laravel App', 'kind' => 'server',     'color' => 'sage',       'x' => 360, 'y' => 80],
                                    ['id' => 'pg',      'label' => 'PostgreSQL', 'kind' => 'database',    'color' => 'sage',       'x' => 560, 'y' => 10],
                                    ['id' => 'redis',   'label' => 'Redis',      'kind' => 'database',    'color' => 'terracotta', 'x' => 560, 'y' => 150],
                                ],
                                'edges' => [
                                    ['from' => 'browser', 'to' => 'nginx', 'fromSide' => 'right', 'toSide' => 'left', 'routing' => 'step'],
                                    ['from' => 'nginx',   'to' => 'app',   'fromSide' => 'right', 'toSide' => 'left', 'routing' => 'step'],
                                    ['from' => 'app',     'to' => 'pg',    'fromSide' => 'right', 'toSide' => 'left', 'routing' => 'step', 'label' => 'SQL'],
                                    ['from' => 'app',     'to' => 'redis', 'fromSide' => 'right', 'toSide' => 'left', 'routing' => 'step', 'label' => 'cache/queue'],
                                ],
                            ],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Request lifecycle'],
                            ['type' => 'orderedList', 'items' => [
                                'Browser sends request → Laravel router',
                                'Controller authorises action via Policy',
                                'Controller fetches data, returns Inertia response',
                                'Inertia serialises props as JSON, React renders page component',
                                'Subsequent navigations are XHR — no full reload',
                            ]],
                            'Database schemas and relational design are described in [[Database Schema & Design]]. Background jobs are covered in [[Queue Systems]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Database Schema & Design',
                                'position' => 1,
                                'content'  => [
                                    'We use PostgreSQL 16. Key tables are listed below.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Core tables'],
                                    ['type' => 'bulletList', 'items' => [
                                        '`workspaces` — name, slug, description, position',
                                        '`documents` — title, slug, workspace_id, parent_id, position, content (jsonb), content_html (text), search_vector (tsvector)',
                                        '`document_versions` — snapshot of content + title on every save',
                                        '`tags` + `taggables` — polymorphic many-to-many',
                                        '`links` — source_document_id, target_document_id, resolved at save time',
                                        '`assets` — path, disk, mime, size, SHA-256 checksum for deduplication',
                                    ]],
                                    ['type' => 'codeBlock', 'language' => 'sql', 'code' =>
                                        "SELECT d.title, COUNT(dv.id) AS versions\nFROM documents d\nLEFT JOIN document_versions dv ON dv.document_id = d.id\nGROUP BY d.id\nORDER BY versions DESC;",
                                    ],
                                    'See [[Architecture Overview]] for how these tables interact with the queue system.',
                                ],
                            ],
                            [
                                'title'    => 'Queue Systems',
                                'position' => 2,
                                'content'  => [
                                    'We use Redis as our queue driver with Laravel Horizon for monitoring. All long-running work (exports, imports, search-vector reindexing) is dispatched as background jobs.',
                                    ['type' => 'image', 'src' => 'https://picsum.photos/seed/queues/900/380', 'alt' => 'Horizon queue dashboard'],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Queue setup'],
                                    ['type' => 'codeBlock', 'language' => 'bash', 'code' => "php artisan horizon\n# or in production:\nsupervisorctl start horizon"],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Dispatched jobs'],
                                    ['type' => 'bulletList', 'items' => [
                                        '`ProcessDocumentImport` — DOCX/PDF → TipTap JSON',
                                        '`GenerateDocumentExport` — TipTap JSON → PDF/DOCX',
                                        '`UpdateSearchVector` — regenerates tsvector on content change',
                                    ]],
                                    'Job dispatching guidelines are in [[Architecture Overview]].',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Code Style Guidelines',
                        'position' => 3,
                        'tags'     => ['style'],
                        'content'  => [
                            'Consistent code style keeps our codebase readable and review-friendly. We enforce rules at the editor level (auto-format) and CI level (lint/pint).',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Quick reference'],
                            ['type' => 'bulletList', 'items' => [
                                'PHP — [[PHP & Laravel Style]] (PSR-12 + Pint)',
                                'React/JS — [[React & Tailwind Style]] (Prettier + ESLint)',
                                'Editor config — [[IDE Settings]]',
                            ]],
                        ],
                        'children' => [
                            [
                                'title'    => 'PHP & Laravel Style',
                                'position' => 1,
                                'tags'     => ['style'],
                                'content'  => [
                                    'We follow PSR-12 enforced by Laravel Pint. Run `./vendor/bin/pint` before committing.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Key conventions'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Thin controllers — extract logic into Service or Action classes',
                                        'Use Form Requests for all validation',
                                        'Every action must go through a Laravel Policy',
                                        'Avoid raw queries — use Eloquent; raw SQL only for reporting',
                                        'Type-hint everything: parameters, return types, properties',
                                    ]],
                                    ['type' => 'codeBlock', 'language' => 'php', 'code' =>
                                        "// Good\npublic function store(StoreDocumentRequest \$request, Workspace \$workspace): RedirectResponse\n{\n    \$this->authorize('create', [Document::class, \$workspace]);\n    \$document = \$this->documentService->create(\$workspace, \$request->validated(), auth()->user());\n    return redirect()->route('documents.show', \$document);\n}",
                                    ],
                                    'Eloquent conventions are described in [[Database Schema & Design]].',
                                ],
                            ],
                            [
                                'title'    => 'React & Tailwind Style',
                                'position' => 2,
                                'tags'     => ['style'],
                                'content'  => [
                                    'We use React 18 (plain JS, no TypeScript) with Tailwind CSS v4. Run Prettier on save.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Component rules'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Functional components only — no class components',
                                        'Keep components small; extract when a component exceeds ~150 lines',
                                        'Co-locate state as close to where it is used as possible',
                                        'Prefer `cn()` (tailwind-merge) for conditional class names',
                                        'No inline `style={}` except for dynamic values Tailwind cannot express',
                                    ]],
                                    'For consistent design tokens, follow the guidelines in the Product workspace\'s [[Design System]].',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Deployment Guide',
                        'position' => 4,
                        'tags'     => ['guide', 'ops'],
                        'content'  => [
                            'Deployments are fully automated via GitHub Actions. Merging a PR into `main` starts the pipeline.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/deploy/900/380', 'alt' => 'CI/CD pipeline overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Pipeline stages'],
                            ['type' => 'orderedList', 'items' => [
                                'Lint — Pint (PHP) + ESLint (JS)',
                                'Test — PHPUnit suite against a fresh SQLite DB',
                                'Build — Vite compiles and fingerprints frontend assets',
                                'Deploy to staging — rsync + `php artisan migrate --force`',
                                'Smoke test — Playwright hits key routes',
                                'Promote to production — manual approval gate in GitHub',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Rollback procedure'],
                            ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                "# On the server\nphp artisan down\ngit checkout <previous-tag>\ncomposer install --no-dev\nnpm ci && npm run build\nphp artisan migrate:rollback\nphp artisan up",
                            ],
                            ['type' => 'blockquote', 'text' => 'Never push directly to main. All changes go through a PR and must pass CI before merging.'],
                        ],
                    ],
                ],
            ],

            // ── 2. Product & Design ───────────────────────────────────────────
            [
                'name'        => 'Product & Design',
                'description' => 'Product roadmaps, UI/UX guidelines, design systems, and user research.',
                'position'    => 2,
                'pages'       => [
                    [
                        'title'    => 'Product Roadmap 2026',
                        'position' => 1,
                        'content'  => [
                            'Our 2026 product focus is on collaborative editing, richer importing, and a polished mobile experience.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/roadmap/900/420', 'alt' => 'Product roadmap board'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Q1 — Foundation'],
                            ['type' => 'bulletList', 'items' => [
                                'Real-time presence indicators (avatars on active documents)',
                                'Operational transforms for concurrent editing (Phase 1)',
                                'DOCX import fidelity improvements',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Q2 — Collaboration'],
                            ['type' => 'bulletList', 'items' => [
                                'Inline comments and threads',
                                'Mention @user in pages to notify teammates',
                                'Page permissions (workspace-level and per-page overrides)',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Q3–Q4 — Polish & Scale'],
                            ['type' => 'bulletList', 'items' => [
                                'Mobile-optimised reading experience',
                                'Full-text search improvements (ranking, filters)',
                                'Analytics: page views, edit frequency, stale-page alerts',
                            ]],
                            'Design specifications live in [[Design System]]. Feedback is collected via the channels described in [[User Research]].',
                        ],
                    ],
                    [
                        'title'    => 'Design System',
                        'position' => 2,
                        'tags'     => ['design'],
                        'content'  => [
                            'The Design System defines the visual language of the product: colours, typography, spacing, radii, shadows, and component patterns.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/designsys/900/420', 'alt' => 'Design system overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Core tokens'],
                            ['type' => 'bulletList', 'items' => [
                                '[[Color Palette]] — warm-cream foundation + soft sage accent',
                                '[[Typography]] — Lexend typeface, strict scale',
                                '[[Component Guidelines]] — buttons, inputs, modals, badges',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Separation comes from borders, not shadows. Use generous whitespace.'],
                            'Frontend implementation details are in [[React & Tailwind Style]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Color Palette',
                                'position' => 1,
                                'tags'     => ['design'],
                                'content'  => [
                                    'We use a warm-cream foundation with a soft sage accent. All colours are defined as CSS custom properties and exposed as Tailwind utilities.',
                                    ['type' => 'image', 'src' => 'https://picsum.photos/seed/palette/900/360', 'alt' => 'Color palette swatches'],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Sage ramp'],
                                    ['type' => 'bulletList', 'items' => [
                                        '`sage-50` #EDF2EA — tinted backgrounds, info banners',
                                        '`sage-100` #DAE6D4 — badge fills, soft callouts',
                                        '`sage-200` #BFD2B5 — borders on sage-tinted areas',
                                        '`sage-400` #7E9D72 — PRIMARY buttons, key accents',
                                        '`sage-600` #4B6840 — sage text on light bg (AA-safe)',
                                    ]],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Semantic colours'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Danger — `#B5573E` warm terracotta (never a cold red)',
                                        'Warning — `#C99650` warm amber',
                                        'Info — `#6E8AA7` muted blue',
                                    ]],
                                    ['type' => 'blockquote', 'text' => 'Text accents use sage-600, not sage-400. sage-400 fails WCAG AA on the cream background.'],
                                ],
                            ],
                            [
                                'title'    => 'Typography',
                                'position' => 2,
                                'tags'     => ['design', 'style'],
                                'content'  => [
                                    'We use Lexend (self-hosted woff2) for all UI text. Monospace content uses JetBrains Mono.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Type scale'],
                                    ['type' => 'bulletList', 'items' => [
                                        'h1 — 24 px / 600 — page titles',
                                        'h2 — 19 px / 600 — section headers',
                                        'h3 — 16 px / 600 — card titles',
                                        'body — 14 px / 400 — default',
                                        'body-lg — 15 px / 400 — document reading view',
                                        'small — 12–13 px / 400 — meta, captions',
                                        'label — 11 px / 500–600 — uppercase labels, tag pills',
                                    ]],
                                    'See [[Design System]] for the full specification and [[Color Palette]] for text colour tokens.',
                                ],
                            ],
                            [
                                'title'    => 'Component Guidelines',
                                'position' => 3,
                                'tags'     => ['design'],
                                'content'  => [
                                    'All shared UI components live in `resources/js/components/ui`. Use these; do not write one-off variants.',
                                    ['type' => 'image', 'src' => 'https://picsum.photos/seed/components/900/400', 'alt' => 'Component library overview'],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Buttons'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Primary — `bg-sage-400` / `text-inverse` / hover `sage-500`',
                                        'Outline — `border-border` / `bg-transparent` / hover `surface-hover`',
                                        'Ghost — `transparent` / `text-primary` / hover `surface-hover`',
                                        'Destructive — `bg-danger` / `text-inverse`',
                                        'Sizes: default 36 px · sm 28 px · lg 44 px — radius-sm (8 px) always',
                                    ]],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Inputs'],
                                    ['type' => 'bulletList', 'items' => [
                                        '`bg-surface border-border rounded-sm` at rest',
                                        'Focus: `border-sage-400 ring-[3px] ring-sage-200`',
                                        'Height 36 px default; 32 px in compact contexts',
                                    ]],
                                    'See [[Design System]] for modal, card, badge, and alert patterns.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'User Research',
                        'position' => 3,
                        'content'  => [
                            'We run periodic usability tests and stakeholder interviews. Findings are synthesised here and feed directly into the [[Product Roadmap 2026]].',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/research/900/380', 'alt' => 'User interview session'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Recurring themes (2025)'],
                            ['type' => 'bulletList', 'items' => [
                                'Users want faster search — full-text isn\'t always surfacing the right page',
                                'Mobile reading experience is poor on long documents',
                                'Tag management is hard to discover; most users rely on the Tags page',
                                'Version history is highly valued by admins',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Next study scheduled for Q1 2026. Reach out to the Product team if you want to observe.'],
                        ],
                    ],
                ],
            ],

            // ── 3. Operations & HR ────────────────────────────────────────────
            [
                'name'        => 'Operations & HR',
                'description' => 'Company onboarding, policies, benefits, and office handbooks.',
                'position'    => 3,
                'pages'       => [
                    [
                        'title'    => 'Onboarding',
                        'position' => 1,
                        'tags'     => ['guide'],
                        'attachments' => [
                            ['name' => 'New Hire Checklist.pdf', 'lines' => [
                                'New Hire Checklist',
                                '',
                                '[ ] Sign employment paperwork',
                                '[ ] Collect laptop and access badge',
                                '[ ] Set up email and single sign-on',
                                '[ ] Meet your onboarding buddy',
                                '[ ] Read the Code of Conduct',
                            ]],
                            ['name' => 'Equipment Request Form.csv',
                                'body' => "item,model,notes\nLaptop,MacBook Pro 14,default issue\nMonitor,Dell U2723QE,optional\nKeyboard,,state preference\n"],
                        ],
                        'content'  => [
                            'Welcome to the team! This page is your starting point. Work through the checklist below during your first week.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/office/900/420', 'alt' => 'Office overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'First week roadmap'],
                            ['type' => 'orderedList', 'items' => [
                                'Complete the [[First Day Checklist]] (accounts, hardware, safety training)',
                                'Read [[Company Benefits]] to understand your entitlements',
                                'Attend the team intro — see [[Meet the Team]] for org chart',
                                'Engineers: follow [[Getting Started]] in the Engineering Hub',
                                'Schedule a 1:1 with your manager by end of week 1',
                            ]],
                        ],
                        'children' => [
                            [
                                'title'    => 'First Day Checklist',
                                'position' => 1,
                                'content'  => [
                                    'Work through these items on your first day. Tick each off as you go — your manager will review progress at your end-of-day check-in.',
                                    ['type' => 'bulletList', 'items' => [
                                        'Set up your laptop (IT will provide the setup guide)',
                                        'Join Slack and introduce yourself in #general',
                                        'Enable 2FA on all company accounts (GitHub, Google Workspace)',
                                        'Read and sign the remote work and data-handling policy',
                                        'Complete the 30-minute online safety training',
                                        'Add your photo and role to the team directory in [[Meet the Team]]',
                                        'Read [[Company Benefits]] — especially the learning budget section',
                                    ]],
                                    ['type' => 'blockquote', 'text' => 'Need help? Ping @hr-team in Slack or refer to [[Onboarding]] for escalation contacts.'],
                                ],
                            ],
                            [
                                'title'    => 'Meet the Team',
                                'position' => 2,
                                'content'  => [
                                    'We are organised into three teams. All contact details and roles are maintained in the [[Office Operations]] directory.',
                                    ['type' => 'image', 'src' => 'https://picsum.photos/seed/team/900/420', 'alt' => 'Team photo'],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Teams'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Engineering — backend, frontend, infrastructure, QA',
                                        'Product & Design — product management, UX, research',
                                        'Operations — HR, finance, admin, IT support',
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Company Benefits',
                        'position' => 2,
                        'attachments' => [
                            ['name' => 'Benefits Summary 2026.pdf', 'lines' => [
                                'Benefits Summary 2026',
                                '',
                                'Health: full medical, dental and vision from day one.',
                                'Time off: 25 days annual leave plus public holidays.',
                                'Pension: 5% employer match.',
                                'Learning: 1000 EUR annual development budget.',
                            ]],
                        ],
                        'content'  => [
                            'We offer a comprehensive benefits package. Full details and how to claim are summarised below.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Health & wellbeing'],
                            ['type' => 'bulletList', 'items' => [
                                'Private health insurance — covers you and immediate family',
                                'Mental health support — 12 counselling sessions per year via Spill',
                                'Gym membership subsidy — up to €50/month',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Time off'],
                            ['type' => 'bulletList', 'items' => [
                                '25 days annual leave + public holidays',
                                'Flexible remote work — no fixed office days required',
                                'Parental leave — 16 weeks fully paid for primary carers',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Growth'],
                            ['type' => 'bulletList', 'items' => [
                                'Learning budget — €1,500/year for courses, books, conferences',
                                'Internal promotion pathway reviewed every 6 months',
                                'Conference talks and open-source contributions encouraged',
                            ]],
                            'For claims and reimbursements, see [[Office Operations]].',
                        ],
                    ],
                    [
                        'title'    => 'Office Operations',
                        'position' => 3,
                        'content'  => [
                            'Practical information for day-to-day office life, hardware requests, and administrative support.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Hardware requests'],
                            ['type' => 'bulletList', 'items' => [
                                'Submit a request in #it-support with the item, estimated cost, and business justification',
                                'Approved within 3 business days for items under €500',
                                'Items over €500 require manager sign-off',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Expense reimbursement'],
                            ['type' => 'bulletList', 'items' => [
                                'Submit receipts via Expensify by the last Friday of each month',
                                'Reimbursed with the following payroll run',
                                'Learning budget claims are separate — tag them as "Learning"',
                            ]],
                            'New starters: your initial setup is handled on the [[First Day Checklist]].',
                        ],
                    ],
                ],
            ],

            // ── 4. Network & Infrastructure ───────────────────────────────────
            [
                'name'        => 'Network & Infrastructure',
                'description' => 'Network topology, VLAN config, firewall rules, monitoring, and runbooks.',
                'position'    => 4,
                'pages'       => [
                    [
                        'title'    => 'Network Overview',
                        'position' => 1,
                        'tags'     => ['network', 'ops'],
                        'content'  => [
                            'This workspace covers the on-premises and cloud networking infrastructure. All changes to production network config require peer review and a change-management ticket.',
                            ['type' => 'diagram', 'name' => 'HQ Network Topology',
                                'settings' => ['routing' => 'step', 'snap' => false],
                                'nodes' => [
                                    ['id' => 'net',  'label' => 'Internet',      'kind' => 'cloud',    'color' => 'blue',       'x' => 280, 'y' => 0],
                                    ['id' => 'fw',   'label' => 'pfSense HA',     'kind' => 'firewall', 'color' => 'terracotta', 'x' => 280, 'y' => 110],
                                    ['id' => 'core', 'label' => 'Catalyst Core',  'kind' => 'switch',   'color' => 'sage',       'x' => 280, 'y' => 220],
                                    ['id' => 'srv',  'label' => 'App Servers',    'kind' => 'server',   'color' => 'sage',       'x' => 90,  'y' => 340],
                                    ['id' => 'db',   'label' => 'PostgreSQL',     'kind' => 'database', 'color' => 'sage',       'x' => 280, 'y' => 340],
                                    ['id' => 'wifi', 'label' => 'Wi-Fi APs',      'kind' => 'ap',       'color' => 'amber',      'x' => 470, 'y' => 340],
                                ],
                                'edges' => [
                                    ['from' => 'net',  'to' => 'fw',   'routing' => 'step'],
                                    ['from' => 'fw',   'to' => 'core', 'routing' => 'step'],
                                    ['from' => 'core', 'to' => 'srv',  'routing' => 'step'],
                                    ['from' => 'core', 'to' => 'db',   'routing' => 'step'],
                                    ['from' => 'core', 'to' => 'wifi', 'routing' => 'step'],
                                ],
                            ],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Site summary'],
                            ['type' => 'bulletList', 'items' => [
                                'HQ — 192.168.1.0/24 (management), 10.10.0.0/16 (servers)',
                                'Remote workers — WireGuard VPN, assigned 10.99.0.0/24',
                                'Cloud (AWS eu-west-1) — 172.16.0.0/12 VPC',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Key pages'],
                            ['type' => 'bulletList', 'items' => [
                                '[[VLANs & Subnets]] — full addressing scheme and VLAN table',
                                '[[Firewall & Security]] — rule sets and change process',
                                '[[Monitoring & Alerts]] — dashboards, alerting, and on-call runbook',
                                '[[DNS & DHCP]] — zone files, DHCP pools, and TTL policy',
                            ]],
                        ],
                        'children' => [],
                    ],
                    [
                        'title'    => 'VLANs & Subnets',
                        'position' => 2,
                        'tags'     => ['network'],
                        'content'  => [
                            'All VLANs are configured on the core Cisco Catalyst 9300 stack. Changes require a tested rollback plan and a 30-minute maintenance window.',
                            ['type' => 'diagram', 'name' => 'VLAN Segmentation',
                                'nodes' => [
                                    ['id' => 'zMgmt',  'group' => true, 'label' => 'VLAN 10 · Management', 'color' => 'blue',       'x' => 20,  'y' => 20,  'w' => 230, 'h' => 120],
                                    ['id' => 'zSrv',   'group' => true, 'label' => 'VLAN 20 · Servers',    'color' => 'sage',       'x' => 280, 'y' => 20,  'w' => 250, 'h' => 120],
                                    ['id' => 'zStaff', 'group' => true, 'label' => 'VLAN 30 · Staff',      'color' => 'amber',      'x' => 20,  'y' => 180, 'w' => 230, 'h' => 120],
                                    ['id' => 'zGuest', 'group' => true, 'label' => 'VLAN 50 · Guest',      'color' => 'terracotta', 'x' => 280, 'y' => 180, 'w' => 250, 'h' => 120],
                                    ['id' => 'mgmt',  'label' => 'Mgmt Switch',  'kind' => 'switch',      'color' => 'blue',       'parent' => 'zMgmt',  'x' => 50, 'y' => 55],
                                    ['id' => 'apps',  'label' => 'App Hosts',    'kind' => 'server',      'color' => 'sage',       'parent' => 'zSrv',   'x' => 55, 'y' => 55],
                                    ['id' => 'staff', 'label' => 'Workstations', 'kind' => 'workstation', 'color' => 'amber',      'parent' => 'zStaff', 'x' => 45, 'y' => 55],
                                    ['id' => 'guest', 'label' => 'Guest Wi-Fi',  'kind' => 'ap',          'color' => 'terracotta', 'parent' => 'zGuest', 'x' => 50, 'y' => 55],
                                ],
                            ],
                            ['type' => 'heading', 'level' => 2, 'text' => 'VLAN table'],
                            ['type' => 'bulletList', 'items' => [
                                'VLAN 10 — Management — 192.168.1.0/24 — switches, APs, OOB',
                                'VLAN 20 — Servers — 10.10.20.0/24 — application + DB hosts',
                                'VLAN 30 — Staff — 10.10.30.0/23 — corporate workstations',
                                'VLAN 40 — IoT — 10.10.40.0/24 — printers, displays, sensors',
                                'VLAN 50 — Guest — 10.10.50.0/24 — internet-only, isolated',
                                'VLAN 99 — Native/Trunk — untagged on uplinks',
                            ]],
                            'Detailed per-VLAN firewall policy is in [[Firewall & Security]]. IP addressing details are in [[IP Addressing Scheme]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'IP Addressing Scheme',
                                'position' => 1,
                                'tags'     => ['network'],
                                'content'  => [
                                    'All static assignments are tracked here. DHCP pools are managed via ISC DHCP and documented in [[DNS & DHCP]].',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Static assignments (VLAN 20)'],
                                    ['type' => 'bulletList', 'items' => [
                                        '10.10.20.1 — Gateway (L3 switch SVI)',
                                        '10.10.20.2 — Primary DNS / DHCP server',
                                        '10.10.20.10 — PostgreSQL primary',
                                        '10.10.20.11 — PostgreSQL replica',
                                        '10.10.20.20 — Redis / Horizon',
                                        '10.10.20.30–39 — Application servers (load-balanced)',
                                        '10.10.20.50 — Monitoring (Prometheus + Grafana)',
                                    ]],
                                    ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                        "# Verify connectivity from app server\nping -c4 10.10.20.10     # PostgreSQL primary\nping -c4 10.10.20.20     # Redis\nnmap -p 5432 10.10.20.10 # check DB port",
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Firewall & Security',
                        'position' => 3,
                        'tags'     => ['network', 'security'],
                        'content'  => [
                            'We run a pfSense 2.7 firewall cluster (active/passive HA) at the perimeter, plus per-VLAN ACLs on the Catalyst core switch.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/security/900/420', 'alt' => 'Firewall architecture diagram'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Default inter-VLAN policy'],
                            ['type' => 'bulletList', 'items' => [
                                'Staff → Servers: allow 80, 443, 22 (SSH via bastion only)',
                                'Staff → Management: deny all (sysadmins only)',
                                'IoT → any: deny all except NTP (UDP 123)',
                                'Guest → any internal: deny all',
                                'Servers → Internet: allow 443, 80 (HTTP via Squid proxy)',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Change process'],
                            ['type' => 'orderedList', 'items' => [
                                'Open a change-management ticket describing the rule and business justification',
                                'Get peer review from a second sysadmin',
                                'Test in staging VLAN first',
                                'Apply during maintenance window (weekdays 02:00–04:00 local)',
                                'Monitor alerts for 30 minutes post-change — see [[Monitoring & Alerts]]',
                            ]],
                            ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                "# pfSense CLI — show active rules\npfctl -sr | grep -v '^#'\n\n# Check blocked traffic in real time\ntcpdump -i em0 -n 'tcp[tcpflags] & (tcp-rst) != 0'",
                            ],
                            ['type' => 'blockquote', 'text' => 'Never open inbound rules from the Internet without a documented risk assessment and manager sign-off.'],
                        ],
                    ],
                    [
                        'title'    => 'Monitoring & Alerts',
                        'position' => 4,
                        'tags'     => ['ops'],
                        'content'  => [
                            'We use Prometheus + Grafana for metrics and Alertmanager for routing alerts to PagerDuty and Slack.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/monitoring/900/460', 'alt' => 'Grafana dashboard overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Alert severity levels'],
                            ['type' => 'bulletList', 'items' => [
                                'P1 — production down or data loss risk → PagerDuty (immediate call)',
                                'P2 — degraded service, one redundant component failed → PagerDuty (15 min ack)',
                                'P3 — non-critical warning → Slack #alerts (next business day)',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Key dashboards'],
                            ['type' => 'bulletList', 'items' => [
                                'Infrastructure — CPU, memory, disk, network per host',
                                'Application — request latency p50/p95/p99, error rate, queue depth',
                                'Database — slow queries, connection pool, replication lag',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Common runbook entries'],
                            ['type' => 'orderedList', 'items' => [
                                'High DB connection count → check for query pile-up: `SELECT count(*) FROM pg_stat_activity WHERE state = \'active\';`',
                                'Redis OOM → check Horizon queue backlog and eviction policy',
                                'Disk full on app server → clear old log files and `/tmp`, alert DevOps',
                            ]],
                            'Network-level issues are covered in [[Network Overview]] and [[Firewall & Security]].',
                        ],
                    ],
                    [
                        'title'    => 'DNS & DHCP',
                        'position' => 5,
                        'tags'     => ['network'],
                        'content'  => [
                            'DNS is served by a primary + secondary BIND9 pair on VLAN 20. DHCP is handled by ISC DHCP on the same hosts. All leases are cross-registered in DNS automatically.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Zone overview'],
                            ['type' => 'bulletList', 'items' => [
                                '`internal.example.com` — all internal hostnames',
                                '`10.10.in-addr.arpa` — reverse zone for 10.10.0.0/16',
                                '`168.192.in-addr.arpa` — reverse zone for management VLAN',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'DHCP pools'],
                            ['type' => 'bulletList', 'items' => [
                                'VLAN 30 (Staff) — 10.10.30.50–10.10.30.254, lease 8 h',
                                'VLAN 40 (IoT) — 10.10.40.10–10.10.40.200, lease 24 h',
                                'VLAN 50 (Guest) — 10.10.50.10–10.10.50.250, lease 1 h',
                            ]],
                            ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                "# Force BIND to reload zone files\nrndc reload internal.example.com\n\n# Check current DHCP leases\ncat /var/lib/dhcp/dhcpd.leases | grep 'binding state active' | wc -l",
                            ],
                            'IP addressing for static hosts is in [[IP Addressing Scheme]]. See [[Network Overview]] for the full site summary.',
                        ],
                    ],
                ],
            ],

            // ── 5. Security & Compliance ──────────────────────────────────────
            [
                'name'        => 'Security & Compliance',
                'description' => 'Security policies, incident response, access control, and data protection.',
                'position'    => 5,
                'pages'       => [
                    [
                        'title'    => 'Security Policy Overview',
                        'position' => 1,
                        'tags'     => ['security', 'policy'],
                        'content'  => [
                            'Security is everyone\'s responsibility. This page is the entry point to our policies; all staff must read it during [[Onboarding]].',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/secpolicy/900/420', 'alt' => 'Security policy overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Core principles'],
                            ['type' => 'bulletList', 'items' => [
                                'Least privilege — grant the minimum access needed, review quarterly',
                                'Defence in depth — no single control is trusted alone',
                                'Assume breach — log everything, alert on anomalies',
                                'Encrypt in transit and at rest — TLS everywhere, encrypted disks',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Policy index'],
                            ['type' => 'bulletList', 'items' => [
                                '[[Access Control Policy]] — who gets access to what',
                                '[[Password & MFA Policy]] — credential requirements',
                                '[[Data Protection & GDPR]] — handling personal data',
                                '[[Incident Response]] — what to do when something goes wrong',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Report anything suspicious to #security immediately. Better a false alarm than a missed breach.'],
                        ],
                        'children' => [
                            [
                                'title'    => 'Access Control Policy',
                                'position' => 1,
                                'tags'     => ['security', 'policy'],
                                'content'  => [
                                    'Access to systems is granted by role and reviewed every quarter. All access requests go through the IT team and require manager approval.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Access tiers'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Standard — email, chat, docs (this app), shared drives',
                                        'Elevated — production read access, monitoring dashboards',
                                        'Privileged — production write, database, infrastructure (sysadmins only)',
                                    ]],
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Offboarding'],
                                    ['type' => 'orderedList', 'items' => [
                                        'Disable SSO account within 1 hour of departure',
                                        'Revoke all API tokens and SSH keys',
                                        'Rotate any shared secrets the person had access to',
                                        'Reassign owned documents and resources',
                                    ]],
                                    'Credential standards are defined in [[Password & MFA Policy]].',
                                ],
                            ],
                            [
                                'title'    => 'Password & MFA Policy',
                                'position' => 2,
                                'tags'     => ['security', 'policy'],
                                'content'  => [
                                    'Strong, unique credentials with multi-factor authentication are mandatory on every company account.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Requirements'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Minimum 14 characters, generated by a password manager',
                                        'Unique per service — never reuse passwords',
                                        'MFA enabled everywhere it is supported (TOTP or hardware key)',
                                        'Hardware security keys required for privileged access',
                                    ]],
                                    ['type' => 'blockquote', 'text' => 'SMS-based MFA is discouraged — prefer TOTP apps or hardware keys where possible.'],
                                    'Who qualifies for privileged access is covered in [[Access Control Policy]].',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Incident Response',
                        'position' => 2,
                        'tags'     => ['security', 'incident'],
                        'content'  => [
                            'When a security or availability incident occurs, follow this process. Speed and clear communication matter more than perfection.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/incident/900/420', 'alt' => 'Incident response flow'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Severity & response'],
                            ['type' => 'bulletList', 'items' => [
                                'SEV1 — active breach or full outage → page on-call, open war room',
                                'SEV2 — partial outage or contained breach → notify #security, assign lead',
                                'SEV3 — minor, no customer impact → ticket, handle in business hours',
                            ]],
                            'The step-by-step runbook is in [[Incident Runbook]]. After resolution, always complete a [[Post-Mortem Template]]. Infrastructure alerting is described in [[Monitoring & Alerts]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Incident Runbook',
                                'position' => 1,
                                'tags'     => ['incident', 'ops'],
                                'attachments' => [
                                    ['name' => 'Severity Matrix.pdf', 'lines' => [
                                        'Incident Severity Matrix',
                                        '',
                                        'SEV1 - full outage or data-loss risk. Page on-call immediately.',
                                        'SEV2 - major feature down. Respond within 15 minutes.',
                                        'SEV3 - degraded, workaround exists. Next business day.',
                                    ]],
                                    ['name' => 'Escalation Contacts.csv',
                                        'body' => "role,contact,channel\nIncident Lead,on-call rotation,#incidents\nEng Manager,rotation,phone\nComms,PR team,email\n"],
                                ],
                                'content'  => [
                                    'Follow these steps for any SEV1 or SEV2. Assign one Incident Lead — they coordinate, others execute.',
                                    ['type' => 'orderedList', 'items' => [
                                        'Declare the incident in #incidents and assign an Incident Lead',
                                        'Contain — isolate affected hosts, revoke compromised credentials',
                                        'Assess scope — what data/systems are affected? See [[Firewall & Security]]',
                                        'Communicate — post status updates every 30 minutes',
                                        'Eradicate & recover — remove the cause, restore from known-good',
                                        'Close — confirm resolution, schedule the post-mortem',
                                    ]],
                                    ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                        "# Quickly isolate a compromised host at the firewall\npfctl -t blocklist -T add 10.10.20.34\n\n# Snapshot logs before they rotate\njournalctl --since '1 hour ago' > /var/incident/\$(date +%s).log",
                                    ],
                                    ['type' => 'blockquote', 'text' => 'Preserve evidence before remediating where feasible — snapshot disks and logs first.'],
                                ],
                            ],
                            [
                                'title'    => 'Post-Mortem Template',
                                'position' => 2,
                                'tags'     => ['incident'],
                                'content'  => [
                                    'Every SEV1/SEV2 gets a blameless post-mortem within 5 business days. Focus on systems and process, never individuals.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Sections to fill in'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Summary — what happened, in two sentences',
                                        'Impact — who/what was affected and for how long',
                                        'Timeline — detection → resolution, with timestamps',
                                        'Root cause — the underlying system failure',
                                        'Action items — concrete fixes with owners and due dates',
                                    ]],
                                    'Link the originating alert and the [[Incident Runbook]] steps that were followed.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Data Protection & GDPR',
                        'position' => 3,
                        'tags'     => ['compliance', 'policy'],
                        'content'  => [
                            'We process personal data lawfully, transparently, and only for clear purposes. This page summarises our obligations under GDPR.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/gdpr/900/400', 'alt' => 'Data protection overview'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Key obligations'],
                            ['type' => 'bulletList', 'items' => [
                                'Lawful basis — document why we hold each category of data',
                                'Data minimisation — collect only what is needed',
                                'Right to erasure — handle deletion requests within 30 days',
                                'Breach notification — report qualifying breaches within 72 hours',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Subject access requests'],
                            ['type' => 'orderedList', 'items' => [
                                'Verify the requester\'s identity',
                                'Locate all personal data held about them',
                                'Provide a copy in a portable format within one month',
                                'Log the request and response for audit',
                            ]],
                            'Breaches involving personal data also trigger [[Incident Response]].',
                        ],
                    ],
                    [
                        'title'    => 'Backup & Disaster Recovery',
                        'position' => 4,
                        'tags'     => ['ops', 'security'],
                        'content'  => [
                            'We follow a 3-2-1 backup strategy and test restores quarterly. An untested backup is not a backup.',
                            ['type' => 'heading', 'level' => 2, 'text' => '3-2-1 strategy'],
                            ['type' => 'bulletList', 'items' => [
                                '3 copies of data — production + two backups',
                                '2 different media — local NAS + cloud object storage',
                                '1 copy off-site — encrypted, in a different region',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Recovery objectives'],
                            ['type' => 'bulletList', 'items' => [
                                'RPO (max data loss) — 1 hour for the primary database',
                                'RTO (max downtime) — 4 hours for full service restoration',
                            ]],
                            ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                                "# Nightly encrypted Postgres backup\npg_dump -Fc app_production | age -r \$BACKUP_KEY > /backups/app-\$(date +%F).dump.age\n\n# Test restore into a scratch database\nage -d -i key.txt /backups/app-2026-06-20.dump.age | pg_restore -d app_restore_test",
                            ],
                            ['type' => 'blockquote', 'text' => 'Restore drills are run on the first Monday of each quarter. Results are recorded in this workspace.'],
                        ],
                    ],
                ],
            ],

            // ── 6. Customer Support ───────────────────────────────────────────
            [
                'name'        => 'Customer Support',
                'description' => 'Support handbook, ticket triage, escalation paths, and the customer FAQ.',
                'position'    => 6,
                'pages'       => [
                    [
                        'title'    => 'Support Handbook',
                        'position' => 1,
                        'tags'     => ['support', 'guide'],
                        'content'  => [
                            'Our support team is the voice of the customer. Be empathetic, be accurate, and never guess — escalate when unsure.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/support/900/420', 'alt' => 'Support team at work'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Principles'],
                            ['type' => 'bulletList', 'items' => [
                                'Acknowledge fast — a quick "we\'re on it" beats a slow perfect answer',
                                'Reproduce before escalating — gather steps, screenshots, account ID',
                                'Close the loop — confirm the fix worked before resolving',
                                'Write it down — recurring issues belong in [[Common Issues & FAQ]]',
                            ]],
                            'New tickets are handled per [[Ticket Triage]]. When something is beyond first-line, follow the [[Escalation Matrix]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Ticket Triage',
                                'position' => 1,
                                'tags'     => ['support'],
                                'content'  => [
                                    'Every incoming ticket is triaged within the response targets in [[SLA & Response Times]]. Categorise, prioritise, then assign.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Priority guide'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Urgent — customer cannot work, no workaround → escalate immediately',
                                        'High — major feature broken, workaround exists',
                                        'Normal — minor bug or how-to question',
                                        'Low — feature request or cosmetic issue',
                                    ]],
                                    ['type' => 'blockquote', 'text' => 'When in doubt about a technical root cause, open an [[Incident Response]] check rather than sitting on it.'],
                                ],
                            ],
                            [
                                'title'    => 'Escalation Matrix',
                                'position' => 2,
                                'tags'     => ['support', 'policy'],
                                'content'  => [
                                    'Escalate when an issue exceeds first-line scope or breaches its SLA. Always include reproduction steps and the account ID.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Escalation paths'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Billing question → Finance team (see [[Invoicing Process]])',
                                        'Suspected bug → Engineering on-call via #eng-support',
                                        'Outage / data issue → trigger [[Incident Response]]',
                                        'Security report → #security immediately',
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Common Issues & FAQ',
                        'position' => 2,
                        'tags'     => ['support'],
                        'content'  => [
                            'A living list of the questions we answer most. Keep it updated — if you answer the same thing twice, add it here.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Account & login'],
                            ['type' => 'bulletList', 'items' => [
                                'Reset password — Settings → Profile → Change password',
                                'Locked out — confirm identity, then IT re-enables the SSO account',
                                'MFA device lost — follow recovery in [[Password & MFA Policy]]',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Documents'],
                            ['type' => 'bulletList', 'items' => [
                                'Restore a deleted page — admins use the Trash view',
                                'See older content — open a page\'s version history',
                                'Export to PDF/DOCX — use the export menu on any document',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Found a gap? Add the question and answer here so the whole team benefits.'],
                        ],
                    ],
                    [
                        'title'    => 'SLA & Response Times',
                        'position' => 3,
                        'tags'     => ['support', 'policy'],
                        'content'  => [
                            'Our service-level targets by priority. These are commitments to customers — protect them.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Targets'],
                            ['type' => 'bulletList', 'items' => [
                                'Urgent — first response 1 h, resolution target 4 h',
                                'High — first response 4 h, resolution target 1 business day',
                                'Normal — first response 1 business day, resolution 3 business days',
                                'Low — first response 2 business days, best-effort resolution',
                            ]],
                            'Triage decisions that affect these timers are described in [[Ticket Triage]].',
                        ],
                    ],
                ],
            ],

            // ── 7. Sales & Marketing ──────────────────────────────────────────
            [
                'name'        => 'Sales & Marketing',
                'description' => 'Sales playbooks, brand guidelines, and the content calendar.',
                'position'    => 7,
                'pages'       => [
                    [
                        'title'    => 'Sales Playbook',
                        'position' => 1,
                        'tags'     => ['sales', 'guide'],
                        'content'  => [
                            'How we sell: consultative, honest, and focused on the customer\'s problem. We win by being genuinely useful, not pushy.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/sales/900/420', 'alt' => 'Sales pipeline board'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Pipeline stages'],
                            ['type' => 'orderedList', 'items' => [
                                'Lead — inbound or outbound contact identified',
                                'Discovery — understand the need (see [[Discovery Calls]])',
                                'Demo — tailored to the problems uncovered',
                                'Proposal — scope and price (see [[Pricing & Quotes]])',
                                'Close — contract signed, handed to onboarding',
                            ]],
                            'All customer-facing material must follow the [[Brand Guidelines]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Discovery Calls',
                                'position' => 1,
                                'tags'     => ['sales'],
                                'content'  => [
                                    'Discovery is about listening, not pitching. Aim for the customer to talk 70% of the time.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Questions to ask'],
                                    ['type' => 'bulletList', 'items' => [
                                        'What does your current process look like?',
                                        'Where does it break down or cost you time?',
                                        'What happens if you do nothing?',
                                        'Who else is involved in this decision?',
                                    ]],
                                    ['type' => 'blockquote', 'text' => 'Log every answer in the CRM the same day — memory fades fast.'],
                                ],
                            ],
                            [
                                'title'    => 'Pricing & Quotes',
                                'position' => 2,
                                'tags'     => ['sales', 'finance'],
                                'content'  => [
                                    'Pricing is per-seat with volume tiers. Discounts above 15% require sales-lead approval.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Tiers'],
                                    ['type' => 'bulletList', 'items' => [
                                        'Starter — up to 10 seats, monthly billing',
                                        'Team — 11–50 seats, annual billing, priority support',
                                        'Business — 50+ seats, custom terms, dedicated contact',
                                    ]],
                                    'Once signed, billing setup follows the [[Invoicing Process]].',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Brand Guidelines',
                        'position' => 2,
                        'tags'     => ['marketing', 'design'],
                        'content'  => [
                            'Our brand is calm, confident, and human. Warm tones, generous space, no hype.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/brand/900/420', 'alt' => 'Brand mood board'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Voice'],
                            ['type' => 'bulletList', 'items' => [
                                'Plain language — explain, don\'t impress',
                                'Active voice and short sentences',
                                'Confident, never arrogant; helpful, never salesy',
                            ]],
                            'Visual tokens (colour, type) align with the product\'s [[Design System]] and [[Color Palette]].',
                        ],
                    ],
                    [
                        'title'    => 'Content Calendar',
                        'position' => 3,
                        'tags'     => ['marketing'],
                        'content'  => [
                            'We publish consistently rather than sporadically. One substantial piece per week beats five rushed ones.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Cadence'],
                            ['type' => 'bulletList', 'items' => [
                                'Mondays — blog post or changelog',
                                'Wednesdays — social highlights and customer stories',
                                'Fridays — newsletter (every other week)',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Draft in this workspace, get one review, then schedule. Themes tie back to the [[Product Roadmap 2026]].'],
                        ],
                    ],
                ],
            ],

            // ── 8. Finance & Legal ────────────────────────────────────────────
            [
                'name'        => 'Finance & Legal',
                'description' => 'Expenses, procurement, invoicing, contracts, and vendor management.',
                'position'    => 8,
                'pages'       => [
                    [
                        'title'    => 'Expense & Procurement',
                        'position' => 1,
                        'tags'     => ['finance', 'policy'],
                        'attachments' => [
                            ['name' => 'Procurement Policy.pdf', 'lines' => [
                                'Procurement Policy',
                                '',
                                'Under 200 EUR: self-approve and keep the receipt.',
                                '200-2000 EUR: manager approval required.',
                                'Over 2000 EUR: finance sign-off and a purchase order.',
                            ]],
                            ['name' => 'Expense Report Template.csv',
                                'body' => "date,category,amount,description\n,,,\n,,,\n"],
                        ],
                        'content'  => [
                            'How to spend company money responsibly. When unsure whether something is reimbursable, ask before you buy.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'Approval thresholds'],
                            ['type' => 'bulletList', 'items' => [
                                'Under €100 — no pre-approval, just submit the receipt',
                                '€100–€500 — manager approval required',
                                'Over €500 — manager + finance sign-off, raise a PO',
                            ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Submitting expenses'],
                            ['type' => 'orderedList', 'items' => [
                                'Photograph the receipt at point of purchase',
                                'Submit via Expensify by the last Friday of the month',
                                'Categorise correctly (travel, software, learning, etc.)',
                                'Reimbursed with the next payroll run',
                            ]],
                            'Hardware requests are also covered operationally in [[Office Operations]].',
                        ],
                    ],
                    [
                        'title'    => 'Invoicing Process',
                        'position' => 2,
                        'tags'     => ['finance'],
                        'content'  => [
                            'How we invoice customers and track payment. Accuracy here protects cash flow and trust.',
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/invoice/900/380', 'alt' => 'Invoicing workflow'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Cycle'],
                            ['type' => 'orderedList', 'items' => [
                                'Contract signed → finance creates the customer record',
                                'Invoice raised on the billing date (see [[Pricing & Quotes]])',
                                'Sent automatically with a 14-day due window',
                                'Reconciled against the bank feed weekly',
                            ]],
                            'Payment expectations are detailed in [[Payment Terms]].',
                        ],
                        'children' => [
                            [
                                'title'    => 'Payment Terms',
                                'position' => 1,
                                'tags'     => ['finance', 'legal'],
                                'content'  => [
                                    'Standard terms are net-14. Longer terms require finance approval and must be written into the contract.',
                                    ['type' => 'heading', 'level' => 2, 'text' => 'Dunning process'],
                                    ['type' => 'orderedList', 'items' => [
                                        'Day 0 — invoice sent',
                                        'Day 15 — friendly reminder if unpaid',
                                        'Day 30 — second notice, pause non-critical service',
                                        'Day 45 — escalate to the account owner and legal',
                                    ]],
                                    'Vendor-side agreements are tracked in [[Contracts & Vendor Management]].',
                                ],
                            ],
                        ],
                    ],
                    [
                        'title'    => 'Contracts & Vendor Management',
                        'position' => 3,
                        'tags'     => ['legal'],
                        'content'  => [
                            'A central record of who we have agreements with, key dates, and renewal status. Nothing should auto-renew unnoticed.',
                            ['type' => 'heading', 'level' => 2, 'text' => 'For every contract, track'],
                            ['type' => 'bulletList', 'items' => [
                                'Counterparty and primary contact',
                                'Start date, term, and notice period',
                                'Renewal date and whether it auto-renews',
                                'Annual cost and payment terms',
                                'Where the signed copy is stored',
                            ]],
                            ['type' => 'blockquote', 'text' => 'Set a reminder 60 days before every renewal date so we never miss a notice window.'],
                            'Any vendor handling personal data must meet the standards in [[Data Protection & GDPR]].',
                        ],
                    ],
                ],
            ],

            // ── 9. Sandbox ────────────────────────────────────────────────────
            [
                'name'        => 'Sandbox',
                'description' => 'Throwaway space for testing. Holds a single kitchen-sink page that exercises every editor feature — handy for checking PDF/DOCX exports without juggling several pages.',
                'position'    => 9,
                'pages'       => [
                    [
                        'title'    => 'Feature Showcase',
                        'position' => 1,
                        'tags'     => ['demo', 'guide'],
                        'content'  => [
                            ['type' => 'paragraph', 'spans' => [
                                'This page exercises every formatting feature the editor supports, so a single export shows how each one survives the trip to PDF and DOCX. Text can be ',
                                ['text' => 'bold', 'bold' => true], ', ',
                                ['text' => 'italic', 'italic' => true], ', ',
                                ['text' => 'underlined', 'underline' => true], ', ',
                                ['text' => 'struck through', 'strike' => true], ', or ',
                                ['text' => 'inline code', 'code' => true], '. You can ',
                                ['text' => 'colour text', 'color' => '#B5573E'], ', ',
                                ['text' => 'highlight it', 'highlight' => '#FDE68A'], ', link to ',
                                ['text' => 'an external site', 'link' => 'https://example.com'], ', or cross-reference pages such as ',
                                ['wikiLink' => 'Architecture Overview'], ' and ',
                                ['wikiLink' => 'Design System'], '.',
                            ]],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Headings & text alignment'],
                            ['type' => 'paragraph', 'align' => 'left',    'spans' => ['This paragraph is left-aligned — the default flow for body copy.']],
                            ['type' => 'paragraph', 'align' => 'center',  'spans' => ['This paragraph is centre-aligned.']],
                            ['type' => 'paragraph', 'align' => 'right',   'spans' => ['This paragraph is right-aligned.']],
                            ['type' => 'paragraph', 'align' => 'justify', 'spans' => ['This paragraph is justified, so the renderer stretches the spacing between words until both the left and right edges line up cleanly against the page margins on every full line of text.']],
                            ['type' => 'heading', 'level' => 3, 'text' => 'A centred sub-heading', 'align' => 'center'],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Lists'],
                            ['type' => 'bulletList', 'items' => [
                                'A plain bullet item',
                                ['text' => 'A bullet with nested children', 'sublist' => ['type' => 'bulletList', 'items' => [
                                    'First nested bullet',
                                    'Second nested bullet',
                                ]]],
                                'Back at the top level',
                            ]],
                            ['type' => 'orderedList', 'items' => [
                                'Ordered lists keep their numbering',
                                ['text' => 'And can nest too', 'sublist' => ['type' => 'orderedList', 'items' => [
                                    'Sub-step one',
                                    'Sub-step two',
                                ]]],
                                'Final step',
                            ]],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Blockquote & code'],
                            ['type' => 'blockquote', 'text' => 'Blockquotes are good for callouts, tips, and the occasional memorable line.'],
                            ['type' => 'codeBlock', 'language' => 'php', 'code' =>
                                "// A fenced code block, with a syntax-language hint\npublic function export(Document \$document): string\n{\n    return (new PdfExporter())->export(\$document);\n}",
                            ],

                            ['type' => 'horizontalRule'],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Table'],
                            ['type' => 'table', 'rows' => [
                                ['Feature', 'PDF export', 'DOCX export'],
                                ['Bold / italic / underline / strike', 'Yes', 'Yes'],
                                ['Inline code & code blocks', 'Yes', 'Yes'],
                                ['Text colour', 'Yes', 'Yes'],
                                ['Highlight', 'Yes', 'Yes'],
                                ['Tables', 'Yes', 'Yes'],
                                ['Diagrams', 'Vector SVG', 'Vector SVG'],
                            ]],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Image'],
                            ['type' => 'image', 'src' => 'https://picsum.photos/seed/showcase/720/360', 'alt' => 'A sample centred image', 'width' => 480, 'align' => 'center'],

                            ['type' => 'heading', 'level' => 2, 'text' => 'Network diagram'],
                            'The diagram below covers every node colour, a grouped zone, all three edge routings, a dashed edge, a double-headed arrow, edge labels, and a custom edge colour.',
                            ['type' => 'diagram', 'name' => 'Feature Showcase Diagram',
                                'settings' => ['routing' => 'curved', 'snap' => false],
                                'nodes' => [
                                    ['id' => 'zone',   'group' => true, 'label' => 'Service Zone', 'color' => 'sage',       'x' => 380, 'y' => 0,   'w' => 320, 'h' => 210],
                                    ['id' => 'client', 'label' => 'Client',  'kind' => 'workstation', 'color' => 'default',    'x' => 0,   'y' => 40],
                                    ['id' => 'cdn',    'label' => 'CDN',     'kind' => 'cloud',       'color' => 'blue',       'x' => 0,   'y' => 150],
                                    ['id' => 'gw',     'label' => 'Gateway', 'kind' => 'firewall',    'color' => 'terracotta', 'x' => 190, 'y' => 95],
                                    ['id' => 'api',    'label' => 'API',     'kind' => 'server',      'color' => 'sage',       'parent' => 'zone', 'x' => 30,  'y' => 45],
                                    ['id' => 'cache',  'label' => 'Cache',   'kind' => 'database',    'color' => 'amber',      'parent' => 'zone', 'x' => 30,  'y' => 130],
                                    ['id' => 'worker', 'label' => 'Worker',  'kind' => 'server',      'color' => 'purple',     'parent' => 'zone', 'x' => 185, 'y' => 90],
                                ],
                                'edges' => [
                                    ['from' => 'client', 'to' => 'gw',     'fromSide' => 'right',  'toSide' => 'left', 'routing' => 'straight', 'label' => 'HTTPS'],
                                    ['from' => 'cdn',    'to' => 'gw',     'fromSide' => 'right',  'toSide' => 'left', 'routing' => 'curved'],
                                    ['from' => 'gw',     'to' => 'api',    'fromSide' => 'right',  'toSide' => 'left', 'routing' => 'curved',   'arrows' => 'both'],
                                    ['from' => 'api',    'to' => 'cache',  'fromSide' => 'bottom', 'toSide' => 'top',  'routing' => 'step', 'lineStyle' => 'dashed', 'label' => 'read/write'],
                                    ['from' => 'api',    'to' => 'worker', 'fromSide' => 'right',  'toSide' => 'left', 'routing' => 'curved',   'color' => '#B5573E', 'label' => 'jobs'],
                                ],
                            ],

                            ['type' => 'paragraph', 'spans' => [
                                'Hard line breaks work too:', ['break' => true],
                                'this sentence begins on a new line within the same paragraph.',
                            ]],
                        ],
                    ],
                ],
            ],
            // ── Feature Demo Workspace ────────────────────────────────────────────────────────
            [
                'name'        => 'Feature Demo & Testing',
                'description' => 'A workspace designed to showcase all TipTap features, tables, marks, and test version diffing and trash.',
                'position'    => 5,
                'pages'       => [
                    [
                        'title'    => 'Complete Typography & Blocks',
                        'position' => 1,
                        'tags'     => ['demo'],
                        'content'  => [
                            ['type' => 'heading', 'level' => 1, 'text' => 'Rich Text Formatting'],
                            ['type' => 'paragraph', 'spans' => [
                                'This is ', ['text' => 'bold', 'bold' => true], ', ',
                                ['text' => 'italic', 'italic' => true], ', ',
                                ['text' => 'underline', 'underline' => true], ', and ',
                                ['text' => 'strikethrough', 'strike' => true], '. We can also have ',
                                ['text' => 'inline code', 'code' => true], ' and ',
                                ['text' => 'colored text', 'color' => '#ef4444'], ' with ',
                                ['text' => 'highlighting', 'highlight' => '#fef08a'], '. ',
                                'Here is a ', ['text' => 'link to Google', 'link' => 'https://google.com'], '. ',
                                'And here is an inline wiki link: ', ['wikiLink' => 'Architecture Overview'], '.',
                            ]],
                            ['type' => 'horizontalRule'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Tables'],
                            ['type' => 'table', 'rows' => [
                                ['Feature', 'Status', 'Notes'],
                                ['Rich text', 'Working', 'All marks active'],
                                ['Tables', 'Working', 'Basic row/col rendering'],
                            ]],
                            ['type' => 'horizontalRule'],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Alignment'],
                            ['type' => 'paragraph', 'align' => 'center', 'spans' => [ 'Centered text' ]],
                            ['type' => 'paragraph', 'align' => 'right', 'spans' => [ 'Right-aligned text' ]],
                            ['type' => 'heading', 'level' => 2, 'text' => 'Lists'],
                            ['type' => 'bulletList', 'items' => ['Bullet 1', ['text' => 'Bullet 2', 'sublist' => ['type' => 'orderedList', 'items' => ['Sub item 1', 'Sub item 2']]]]],
                            ['type' => 'horizontalRule'],
                            ['type' => 'blockquote', 'text' => 'This is a blockquote to show how quotes are rendered.'],
                        ],
                    ],
                    [
                        'title'    => 'Version History Test Page',
                        'position' => 2,
                        'tags'     => ['demo'],
                        'content'  => [
                            'This is the initial version of the content. It will be updated shortly.',
                            ['type' => 'table', 'rows' => [
                                ['Initial', 'Data'],
                                ['1', '2'],
                            ]],
                        ],
                    ],
                    [
                        'title'    => 'Trashed Page',
                        'position' => 3,
                        'tags'     => ['demo'],
                        'content'  => [
                            'This page will be moved to the trash to test the Trash UI.',
                        ],
                    ],
                ],
            ],
        ];

        // ── Workspace groups ──────────────────────────────────────────────────
        // Sidebar shelves that file workspaces under a heading. Each entry maps a
        // group name to the workspaces (by name) that live under it, in order.
        // Any workspace not listed here stays ungrouped (top level) — Sandbox and
        // Feature Demo & Testing are deliberately left ungrouped so both the
        // grouped and ungrouped sidebar states are represented.
        $groupLayout = [
            'Engineering' => ['Engineering Hub', 'Product & Design', 'Network & Infrastructure'],
            'Operations'  => ['Operations & HR', 'Security & Compliance', 'Finance & Legal'],
            'Go-to-Market' => ['Customer Support', 'Sales & Marketing'],
        ];

        $groupOf = [];   // workspace name => WorkspaceGroup
        $groupPos = 1;
        foreach ($groupLayout as $groupName => $members) {
            $group = WorkspaceGroup::create([
                'name'     => $groupName,
                'position' => $groupPos++,
            ]);
            foreach ($members as $wsName) {
                $groupOf[$wsName] = $group;
            }
        }

        // ── Seeding logic ─────────────────────────────────────────────────────
        foreach ($workspacesData as $wData) {
            $workspace = Workspace::create([
                'name'        => $wData['name'],
                'description' => $wData['description'],
                'position'    => $wData['position'],
                'group_id'    => $groupOf[$wData['name']]->id ?? null,
            ]);

            foreach ($wData['pages'] as $pageData) {
                $this->createPage($workspace->id, $pageData, null, $authorIds, $tags);
            }
        }

        // ── Shared top-level order ─────────────────────────────────────────────
        // Groups and ungrouped workspaces share ONE position sequence (exactly as
        // the reorder UI persists it), so a loose workspace can sit *between* two
        // groups. Here Sandbox lands between Engineering and Operations, and
        // Feature Demo & Testing trails the last group — a live demo of interleaving
        // straight out of the seeder. DB writes so un-filed positions don't bump
        // updated_at (structural, not an edit).
        $topLevel = [
            ['group', 'Engineering'],
            ['workspace', 'Sandbox'],
            ['group', 'Operations'],
            ['group', 'Go-to-Market'],
            ['workspace', 'Feature Demo & Testing'],
        ];
        foreach ($topLevel as $position => [$type, $name]) {
            $table = $type === 'group' ? 'workspace_groups' : 'workspaces';
            \Illuminate\Support\Facades\DB::table($table)
                ->where('name', $name)
                ->when($type === 'workspace', fn ($q) => $q->whereNull('group_id'))
                ->update(['position' => $position]);
        }

        // ── Page folders ───────────────────────────────────────────────────────
        // File some workspaces' root pages into folders. Most workspaces stay flat
        // and one folder is left empty on purpose, so the sidebar shows every
        // state: foldered pages, loose pages beside folders, and an empty folder.
        $folderLayout = [
            'Engineering Hub' => [
                ['name' => 'Guides', 'pages' => ['Getting Started']],
                ['name' => 'Architecture & Standards', 'pages' => ['Architecture Overview', 'Code Style Guidelines']],
                // Deployment Guide stays loose alongside the folders.
            ],
            'Operations & HR' => [
                ['name' => 'People Ops', 'pages' => ['Onboarding', 'Company Benefits']],
                // Office Operations stays loose.
            ],
            'Security & Compliance' => [
                ['name' => 'Policies', 'pages' => ['Security Policy Overview', 'Data Protection & GDPR']],
                ['name' => 'Runbooks', 'pages' => ['Incident Response']],
                ['name' => 'Audit Evidence', 'pages' => []],   // empty folder, on purpose
                // Backup & Disaster Recovery stays loose.
            ],
        ];
        $this->seedFolders($folderLayout, $authorIds);

        // Re-resolve wiki-links created before their target document existed
        $this->command->info('Syncing wiki-links across seeded pages...');
        \App\Models\Link::whereNull('target_document_id')
            ->get()
            ->each(function ($link) {
                $target = Document::where('title', $link->target_title)->first();
                if ($target) {
                    $link->update(['target_document_id' => $target->id]);
                }
            });

        // ── Post-Seeding Actions (Version Updates & Trash) ─────────────────────
        $this->command->info('Creating version updates and trashing documents...');
        
        $versionPage = Document::where('title', 'Version History Test Page')->first();
        if ($versionPage) {
            auth()->loginUsingId($authorIds[array_rand($authorIds)]);
            $versionPage->update([
                'title' => 'Version History Test Page (Updated)',
                'content' => $this->buildContent([
                    'This is the updated version of the content.',
                    'A new line was added here.',
                    ['type' => 'table', 'rows' => [
                        ['Updated', 'Data', 'Extra'],
                        ['1', '2', '3'],
                    ]],
                ])
            ]);
        }

        $trashedPage = Document::where('title', 'Trashed Page')->first();
        if ($trashedPage) {
            auth()->loginUsingId($authorIds[array_rand($authorIds)]);
            $trashedPage->delete();
        }
        
        $trashedWorkspace = Workspace::create([
            'name'        => 'Deleted Workspace',
            'description' => 'This workspace was soft-deleted.',
            'position'    => 99,
        ]);
        $trashedWorkspace->delete();

        // ── User Document Interactions ─────────────────────────────────────────────
        $this->command->info('Seeding starred and recently viewed documents...');
        
        // Give everyone a few starred and recently viewed pages
        $users = User::all();
        $docs = Document::take(10)->get();
        
        if ($docs->count() > 0) {
            foreach ($users as $u) {
                // Star 2 random documents
                $starred = $docs->random(min(2, $docs->count()));
                foreach ($starred as $doc) {
                    \Illuminate\Support\Facades\DB::table('document_user')->updateOrInsert(
                        ['user_id' => $u->id, 'document_id' => $doc->id],
                        ['starred_at' => now()]
                    );
                }
                
                // View 4 random documents
                $viewed = $docs->random(min(4, $docs->count()));
                foreach ($viewed as $doc) {
                    \Illuminate\Support\Facades\DB::table('document_user')->updateOrInsert(
                        ['user_id' => $u->id, 'document_id' => $doc->id],
                        ['last_viewed_at' => now()->subMinutes(rand(1, 1440))]
                    );
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create page folders and file the named ROOT pages into them. Folders and
     * loose root pages share ONE top-level position space (a folder can sit above
     * or between loose pages), so folders take the first slots and the remaining
     * loose pages are renumbered after them.
     *
     * Filing a page is STRUCTURAL — folder_id only, never content/title — so it
     * must not bump updated_at or the version counter. We save inside
     * withoutTimestamps() exactly like DocumentController::refile, and record the
     * same audit events (folder.created + document.moved) so the trail matches the
     * app's own write path.
     *
     * @param  array<string, array<int, array{name: string, pages?: array<int, string>}>>  $folderLayout
     * @param  array<int, int>  $authorIds
     */
    protected function seedFolders(array $folderLayout, array $authorIds): void
    {
        $this->command->info('Filing pages into page folders...');

        foreach ($folderLayout as $workspaceName => $folders) {
            $workspace = Workspace::where('name', $workspaceName)->first();
            if (! $workspace) {
                continue;
            }

            // Attribute the folder creation and refiles to a real content author.
            $actorId = $authorIds[array_rand($authorIds)];
            auth()->loginUsingId($actorId);

            $topPos = 0;   // shared top-level order: folders first, loose pages after

            foreach ($folders as $spec) {
                $folder = $workspace->folders()->create([
                    'name'     => $spec['name'],
                    'position' => $topPos++,
                ]);

                Audit::record('folder.created', $folder, [
                    'name'      => $folder->name,
                    'workspace' => $workspace->name,
                ], $actorId);

                $memberPos = 0;
                foreach ($spec['pages'] ?? [] as $title) {
                    $page = $workspace->documents()
                        ->whereNull('parent_id')
                        ->where('title', $title)
                        ->first();
                    if (! $page) {
                        continue;
                    }

                    $page->folder_id = $folder->id;
                    $page->position  = $memberPos++;
                    Document::withoutTimestamps(fn () => $page->save());

                    Audit::record('document.moved', $page, [
                        'title'       => $page->title,
                        'from_folder' => null,
                        'to_folder'   => $folder->name,
                    ], $actorId);
                }
            }

            // Renumber the loose root pages that stayed behind so they trail the
            // folders. Structural — write the column directly, leaving updated_at
            // and the observer untouched.
            $loose = $workspace->documents()
                ->whereNull('parent_id')
                ->whereNull('folder_id')
                ->orderBy('position')
                ->pluck('id');
            foreach ($loose as $id) {
                DB::table('documents')->where('id', $id)->update(['position' => $topPos++]);
            }
        }

        auth()->logout();
    }

    protected function createPage(int $workspaceId, array $pageData, ?int $parentId, array $authorIds, array $tags): void
    {
        $createdBy = $authorIds[array_rand($authorIds)];
        $updatedBy = $authorIds[array_rand($authorIds)];

        // Act as the last editor so DocumentObserver stamps updated_by_id (and the
        // version snapshot's author) to them; created_by_id is set explicitly, and
        // the observer leaves an already-set value alone.
        auth()->loginUsingId($updatedBy);

        $document = Document::create([
            'workspace_id'   => $workspaceId,
            'parent_id'      => $parentId,
            'title'          => $pageData['title'],
            'position'       => $pageData['position'],
            'content'        => $this->buildContent($pageData['content']),
            'created_by_id'  => $createdBy,
        ]);

        foreach ($pageData['tags'] ?? [] as $tagKey) {
            if (isset($tags[$tagKey])) {
                $document->tags()->attach($tags[$tagKey]->id);
            }
        }

        $this->attachFiles($document, $pageData['attachments'] ?? [], $createdBy);

        foreach ($pageData['children'] ?? [] as $childData) {
            $this->createPage($workspaceId, $childData, $document->id, $authorIds, $tags);
        }
    }

    /**
     * Attach demo files to a page. Each spec is ['name' => 'Report.pdf', ...] with
     * either 'lines' (rendered into a real one-page PDF) or 'body' (raw text for
     * csv/txt/md). Binaries land on the private 'local' disk, exactly like a real
     * upload, so the download endpoint serves genuinely openable files.
     *
     * @param array<int, array<string, mixed>> $files
     */
    protected function attachFiles(Document $document, array $files, int $uploaderId): void
    {
        foreach (array_values($files) as $position => $spec) {
            $ext   = strtolower(pathinfo($spec['name'], PATHINFO_EXTENSION)) ?: 'txt';
            $bytes = $ext === 'pdf'
                ? $this->pdfBytes((array) ($spec['lines'] ?? [$spec['name']]))
                : (string) ($spec['body'] ?? '');

            $path = 'attachments/' . Str::ulid() . '.' . $ext;
            Storage::disk('local')->put($path, $bytes);

            $document->attachments()->create([
                'disk'           => 'local',
                'path'           => $path,
                'original_name'  => $spec['name'],
                'mime'           => match ($ext) {
                    'pdf'   => 'application/pdf',
                    'csv'   => 'text/csv',
                    'md'    => 'text/markdown',
                    default => 'text/plain',
                },
                'size'           => strlen($bytes),
                'checksum'       => hash('sha256', $bytes),
                'uploaded_by_id' => $uploaderId,
                'position'       => $position + 1,
            ]);
        }
    }

    /**
     * Build a minimal but VALID single-page PDF (Helvetica, one line per entry)
     * with a correct xref table, so seeded attachments actually open in a viewer.
     *
     * @param array<int, string> $lines
     */
    protected function pdfBytes(array $lines): string
    {
        $escape = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);

        $content = "BT\n/F1 14 Tf\n72 760 Td\n18 TL\n";
        $lines   = $lines ?: [''];
        foreach (array_values($lines) as $i => $line) {
            $content .= '(' . $escape($line) . ') Tj' . ($i < count($lines) - 1 ? " T*\n" : "\n");
        }
        $content .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }

        $xref  = strlen($pdf);
        $count = count($objects) + 1;
        $pdf  .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
