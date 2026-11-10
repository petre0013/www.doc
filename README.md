<p align="center">
  <img src="https://raw.githubusercontent.com/petre0013/www.doc/main/art/logomd.svg" alt="www.doc" width="520">
</p>

<p align="center">
  A self-hosted, wiki-style internal knowledge base.
</p>

---

## Features

- Rich editor with wiki-links, tables, images and network diagrams
- Full version history with side-by-side compare and restore
- Full-text search
- DOCX/PDF import and export
- Workspaces, folders, tags and templates
- Admin / editor / viewer roles, with optional member-only workspaces
- Document control: IDs, owners, classification, review cycles and four-eyes approval
- Tamper-evident audit log with retention settings and CSV export
- Scheduled, optionally encrypted backups (local disk or SMB) with weekly verification
- Optional NIS2 starter templates for the Art. 21(2) measures and Art. 23 reporting
- NIS2 registers: requirements, incidents, risks, assets, suppliers, training and tasks
- Audit pack export with approved PDFs and a hash manifest

## Built for NIS2 evidence

www.doc helps you document and evidence NIS2 work. It doesn't make an
organisation compliant by itself, and its templates are starting points to adapt
to your national transposition.

**Document control** is optional per page. Choose *Place under document control*
to give a page an ID, owner, named approver, classification and review cycle.

- Pages move through draft, in review, approved and retired.
- Each sign-off is tied to a SHA-256 hash of the exact version approved.
- Approvers can't approve their own submission or edit, and a page changed after
  submission must be resubmitted.
- Viewers read the approved version while editors keep working on a draft.
- The **Document control** register shows overdue reviews and what was in force
  on any past date.

**Restricted workspaces** are visible only to their members and to admins. Search,
tags, links and lists are filtered too, and non-members get a 404. A member's
global role still decides whether they can edit. Admins set this from the
workspace menu under *Manage access*.

**The audit trail** is append-only in the database and hash-chained, and
`audit:verify` checks it daily. It records sign-ins, content changes, exports,
attachment activity, backup downloads and access changes. Admins can set
retention and export CSV from **Settings > Audit**. Keep regular exports off the
system: the chain detects edited or removed rows, but not removal of the newest
ones.

**Registers** are available to editors and admins under **Registers**.

- *Requirements* can be filled from a built-in NIS2 list, and the dashboard flags
  anything marked implemented with no linked evidence.
- *Incidents* marked significant show the Art. 23 deadlines: 24 hours and 72 hours
  from awareness, and one month from the notification.
- *Risks* are scored for likelihood and impact, and an admin records acceptance of
  residual risk.
- *Assets, suppliers, training records and tasks* share the same owners, due dates
  and links to pages or other records.

**Reminders:** owners get a daily email about work due within 14 days, approvers
about pages awaiting sign-off, and owners and admins an alert when an incident
deadline is within 6 hours. Mail must be configured.

**Read confirmation:** a controlled page can require everyone with access to
confirm they've read the approved version.

**Audit pack:** admins can download a ZIP from **Document control** with the
registers, sign-offs, approved PDFs and a SHA-256 manifest.

**Accounts** are deactivated rather than deleted, so history stays attributable.
Passwords need at least 12 characters, and changing one ends other sessions.
Anyone signing in with a shorter password must replace it. Admins can anonymise
a deactivated account for GDPR erasure; audit events and sign-offs keep the name
recorded at the time until retention removes them.
Multi-factor authentication isn't available yet.

**Evidence retention:** attachments removed from a page stay with its version
history until the page is purged. Uploaded images require a signed-in session.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `WWWDOC_VERSION` | `latest` | Pin the app images to a release (recommended for production) |
| `SESSION_LIFETIME` | `60` | Idle timeout in minutes |
| `REMEMBER_MINUTES` | `10080` | How long "Keep me signed in" lasts |
| `PASSWORD_BREACH_CHECK` | `false` | Reject known-breached passwords (calls the Have I Been Pwned API) |
| `AUDIT_SYSLOG_HOST`, `AUDIT_SYSLOG_PORT` | unset, `514` | Stream audit events to a syslog collector over UDP |
| `CLAMAV_HOST`, `CLAMAV_PORT` | unset, `3310` | Scan attachments and imports with clamd; uploads are refused if it's unreachable |

Scheduled jobs: `audit:verify` (daily), `backup:verify` (weekly, including a trial
load into throwaway tables) and `reminders:send`. All can also be run by hand with
`php artisan`.

The interface is English-only for now. If your content is in another language, set
`DB_SEARCH_LANGUAGE` (for example `spanish`) and run `php artisan search:reindex`.

## Install

Runs with Docker Compose using the pre-built images
[`petre0013/www.doc-app`](https://hub.docker.com/r/petre0013/www.doc-app) and
[`petre0013/www.doc-web`](https://hub.docker.com/r/petre0013/www.doc-web).

```bash
mkdir www.doc && cd www.doc
curl -O https://raw.githubusercontent.com/petre0013/www.doc/main/docker-compose.prod.yml
curl -o .env https://raw.githubusercontent.com/petre0013/www.doc/main/.env.example
# edit .env: set APP_URL, DB_PASSWORD and APP_KEY
docker compose -f docker-compose.prod.yml up -d
```

Open `APP_URL` in a browser and finish the setup wizard right away.

Want HTTPS behind a reverse proxy (one IP, many apps), or deploying with Komodo / Portainer?
See the **[Installation Guide](docs/installation.md)**.

## Development

```bash
git clone https://github.com/petre0013/www.doc.git && cd www.doc
cp .env.example .env
docker compose up --build -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Then open http://localhost:8000 and sign in as `admin@example.com` / `password`.

## License

[MIT](LICENSE)
