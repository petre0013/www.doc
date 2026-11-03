<p align="center">
  <img src="https://raw.githubusercontent.com/petre0013/www.doc/main/art/logomd.svg" alt="www.doc" width="520">
</p>

<p align="center">
  A self-hosted, wiki-style internal knowledge base.
</p>

---

## Features

- **Editor** — TipTap/ProseMirror. Paste from Word/HTML keeps structure; text
  colour, highlights, resizable images.
- **Image interception** — pasted and external images are re-hosted as local
  assets, deduplicated by SHA-256.
- **Wiki-links & backlinks** — `[[Page Title]]` links, indexed on save.
- **Network diagrams** — node-and-edge diagrams in the editor; searchable,
  versioned, exported as vector graphics.
- **Versioning** — every save snapshots content, title, tags and attachments.
  Any two versions (or pages) can be diffed — word-level for text, a visual
  overlay for diagrams — and any version restored.
- **Concurrent-edit protection** — optimistic locking; a conflict dialog shows
  both versions and lets you reload theirs or overwrite with yours.
- **Full-text search** — PostgreSQL FTS across pages, workspaces, tags and
  diagram labels, with highlighted excerpts.
- **Import & export** — DOCX/PDF in both directions as background jobs; drop
  files anywhere in the app to batch-import them.
- **Attachments** — arbitrary files per page, included in backups.
- **Organization** — top-level workspaces, optionally filed into collapsible
  sidebar groups; pages shallow-nested or filed into folders; cross-workspace
  tags, page templates, starred and recently viewed pages. Each workspace list
  and page tree has an Edit mode for drag-reordering and moving items between
  groups or folders.
- **Appearance** — light/dark/system mode and five accent themes, per user.
- **Roles & admin** — admin / editor / viewer roles, user management, Trash
  with restore/purge.
- **Audit log** — append-only trail of content, user, settings, backup and
  login events; filterable in the admin area, carried inside every backup.
- **Encrypted backups** — scheduled or manual `.zip` snapshots to local disk or
  SMB, optional XChaCha20-Poly1305 encryption, email reports, pre-restore
  safety snapshot. See the [Backups & Encryption Guide](docs/backups.md).
- **Setup wizard** — first-run admin account, instance name and SMTP in the
  browser. Optional update check against GitHub releases (off by default).

## Stack

- **Laravel 13** (PHP 8.3) + **Inertia.js** — server-driven, not a REST API.
- **React 19** (JavaScript) + **shadcn/ui** + **Tailwind v4**.
- **PostgreSQL 16** (`jsonb` content, `tsvector` search) + **Redis** (sessions, cache, queue).
- Conversions and other heavy work run as queued jobs (the `worker` service).
- **Pest** (backend) + **Playwright** (browser) test suites.

TipTap JSON is the single source of truth for document content — HTML, DOCX,
PDF and the search vector are derived from it.

## Quick start (development)

Requires Docker with Compose v2.

```bash
git clone <your-fork-url> www.doc && cd www.doc
cp .env.example .env

docker compose up --build          # first run builds the images
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed   # schema + demo data
```

The app is at **http://localhost:8000** (Vite HMR on `5173`). The seeder
creates demo users across all three roles (password `password`);
`admin@example.com` has full access. The dev stack includes **Mailpit**
(outbound email viewer at **http://localhost:8025**) and a dummy **Samba**
server for SMB backups (host `samba`, share `backups`, user `smbuser`,
password `smbpassword`).

### Common commands & tests

```bash
docker compose exec app php artisan test                        # Pest suite
docker compose exec app php artisan test --filter='wiki-links'  # single test
docker compose exec app php artisan migrate:fresh --seed        # reset DB
docker compose exec vite npm install <pkg>                      # add a frontend dep

E2E_PASSWORD=password APP_URL=http://localhost:8000 npx playwright test  # browser tests
```

Browser-only flows (editor, diagram canvas, conflict dialog, imports) live in
`tests/e2e/`. CI runs both suites on every push and pull request against a
freshly built production stack (`.github/workflows/ci.yml`).

> Database-touching artisan commands must **not** pass `--no-deps` — they need
> the `db`/`redis` links.

## Install (production)

Production runs on pre-built images from GitHub Container Registry; no clone
or build step. The **[Installation Guide](docs/installation.md)** covers the
deployment files, environment variables, TLS/reverse-proxy strategies,
updating, and GUI managers (Komodo, Portainer).

The `scheduler` service runs housekeeping; each command is also safe by hand:

- `php artisan assets:prune` — sweeps unreferenced image uploads (daily).
- `php artisan audit:prune` — drops audit events past the retention window,
  365 days by default (daily). The one sanctioned delete path for audit rows.
- `php artisan backup:run` — takes a backup when the configured cadence has
  elapsed; no-op otherwise (hourly).

## Project layout

- `app/Services/RenderDocument.php` — the one TipTap-JSON ↔ HTML renderer/parser.
- `resources/js/components/editor/TipTapEditor.jsx` — the editor schema (kept in sync with `RenderDocument`).
- `app/Services/Importers` / `Exporters` — DOCX/PDF conversion (queued jobs).
- `app/Services/Backup` — snapshotting, encryption, import, restore.
- `app/Support/Audit.php` — the single writer of the append-only audit trail.
- `app/Support/SearchVector.php` — the single SQL definition of the search vectors.
- `app/Support/DiagramSvg.php` & `process_svg.js` — server-side diagram SVG.
- `resources/js/Pages` — Inertia React pages; `tests/Feature` / `tests/Unit` — Pest; `tests/e2e` — Playwright.

## License

See [LICENSE](https://github.com/petre0013/www.doc/blob/main/LICENSE).
