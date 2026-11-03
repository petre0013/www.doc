# Backups & Encryption Guide

The backup engine snapshots the PostgreSQL database and all local uploads into
a single `.zip` archive. Backups are configured from **Settings > Backups** —
scheduled or manual — and written to local disk or an SMB network share.

## Choosing a destination

- **Local disk** writes to the app's own storage volume — the same volume that
  holds the data being backed up. It survives application mistakes (a bad edit,
  an unwanted restore) but not disk failure, volume deletion, or host loss,
  which take data and backups together. Use it for testing or as a secondary
  copy.
- **SMB network share** writes off-host, to a share on a different machine (NAS,
  file server). This is the destination for production. With an encryption key,
  archives are safe to store on shared infrastructure.

The **Test connection** button verifies connectivity and write access before
the first scheduled run.

## What's inside an archive

Each archive holds two layers:

- **Canonical layer** — the full content model as JSON/NDJSON (pages, versions,
  workspaces, tags, links and page templates) plus every asset and attachment
  binary. This is the authoritative restore source, integrity-checked against
  per-file SHA-256s.
- **Readable layer** — a PDF per page, foldered by tree, for humans and
  auditors.

Restores always rebuild from the canonical layer, never the PDFs. The archive's
`manifest.json` records which app version created it.

Per-user navigation state (starred pages, recently-viewed timestamps) is
deliberately **not** part of backups — it's personal ephemera, not content, and
a restore resets it.

The audit trail travels inside every archive (`canonical/audit_events.ndjson`)
and is **merged** on restore — never wiped — so restoring an old backup can't
erase newer audit history.

## Restore & import

- Every restore first takes a **safety snapshot** of the current state, and
  aborts if that snapshot can't be written.
- You can **import** an archive from file (e.g. from another instance).
  Encrypted archives prompt for the key; a wrong or missing key still registers
  the archive, flagged as undecryptable.

## Reports

After each unattended run the app emails a success/failure report (using the
instance's SMTP settings), or shows a persistent in-app notice when mail is off
or fails.

## Encrypted backups

With an encryption key set, archives are encrypted at rest with
**XChaCha20-Poly1305** (via `libsodium`) before being written.

1. **Generate a key** in **Settings > Backups** — a 32-byte base64 key, made in
   the browser.
2. **Add it to the environment** as `BACKUP_ENCRYPTION_KEY=your_base64_string`
   in `.env` (or the GUI's environment config).
3. **Recreate the container** so the variable is injected: `docker compose up
   -d`, or **Deploy** / **Update** in a GUI manager (a plain restart is usually
   not enough).
4. **Toggle "Encrypt backups"** in the UI.

Encryption and decryption then happen transparently during automated
operations, including restores from the UI.

### Manual decryption (disaster recovery)

If your server crashes and you need to extract a `.zip.enc` backup manually
without the app running, you cannot use standard base64 decoding tools — use
the provided emergency CLI tool, which properly streams the `libsodium`
decryption:

```bash
docker compose exec app php artisan backup:decrypt /path/to/your/backup.zip.enc --key="YOUR_BASE64_KEY"
```

If `--key` is omitted, the command uses the key configured in your `.env` file.

## Scheduling

The `scheduler` service checks hourly whether the admin-configured cadence has
elapsed and runs `php artisan backup:run` when due. The command itself is the
gate, so it's safe to run by hand and is a no-op when backups are disabled or
not yet due.
