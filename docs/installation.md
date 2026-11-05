# Installation Guide

**www.doc** ships as pre-built Docker images on the GitHub Container Registry.
Running it in production needs no clone or build step.

## Prerequisites

- A Linux server, VM, or NAS.
- **Docker** and **Docker Compose v2** ([install Docker](https://docs.docker.com/engine/install/)
  — Compose v2 is included). Check with `docker compose version`.

---

## 1. Download the deployment files

```bash
mkdir www.doc && cd www.doc
curl -O https://raw.githubusercontent.com/petre0013/www.doc/main/docker-compose.prod.yml
curl -o .env https://raw.githubusercontent.com/petre0013/www.doc/main/.env.example
```

## 2. Configure the environment

Edit `.env`. Only a few variables need setting:

| Variable | Description |
|----------|-------------|
| `APP_URL` | The exact address users type in the browser (e.g. `https://docs.example.com` or `http://192.0.2.50:8080`). Used to build links in emails and PDF exports. |
| `DB_PASSWORD` | A strong password for the internal PostgreSQL database. Not needed anywhere else. |
| `APP_KEY` | *(Optional)* A 32-byte base64 string (`base64:…`). Leave blank and the app generates one on first boot, stored in its Docker volume. Set it manually only to keep a visible copy in `.env`. Generate with `openssl rand -base64 32`. |
| `BACKUP_ENCRYPTION_KEY` | *(Optional)* Enables encrypted backups. Generate with `openssl rand -base64 32`. **Store it somewhere safe and separate** — an encrypted backup cannot be restored without it. Leave blank for unencrypted backups. |

Email (`MAIL_*`) is not configured here — the setup wizard handles it after boot.

## 3. Start the app

Pick the option that matches how the app is reached:

- **Option A** — a trusted home/office network, or you already run a reverse
  proxy. Simplest; start here if unsure.
- **Option B** — internal use where you want HTTPS but have no proxy.

### Option A: Plain HTTP (port 8080)

Starts the app on port `8080` over HTTP:

```bash
docker compose -f docker-compose.prod.yml up -d
```

That's all you need on a trusted network. For public exposure, put your own
TLS-terminating proxy (Nginx, Caddy, Traefik) in front — see
[`Caddyfile.example`](../Caddyfile.example) for a sample config.

> **Behind a proxy?** Set `TRUSTED_PROXIES` in `.env` to the proxy's address or
> subnet so the app logs each visitor's real IP (for the audit log and login
> throttling) rather than the proxy's. It defaults to private ranges, correct
> when the proxy runs on the same Docker network. Use `*` only if you fully
> control what can reach the app — it lets any client spoof its IP.

### Option B: Internal network with self-signed TLS

For internal use (a local IP or `.local` hostname) without a proxy, a bundled
compose file adds a Caddy container that generates a self-signed certificate.

1. Set `APP_URL` to `https://` plus the exact name users type, e.g.
   `https://docs.corp.lan` (the DNS record you created) or `https://192.0.2.10`.
   The Caddy container refuses to start if it doesn't begin with `https://`.
2. If users also reach the site by other names or IPs (a short name, the
   server's IP, …), list them in `TLS_HOSTS`, e.g. `TLS_HOSTS=docs, 192.0.2.10`.
   Caddy only has certificates for `APP_URL`'s host plus `TLS_HOSTS`; browsing
   by any other name fails with `ERR_SSL_PROTOCOL_ERROR`. Check what it serves
   with `docker compose -f docker-compose.tls.yml logs caddy | grep 'serving HTTPS'`.
3. Download the TLS compose file:
   ```bash
   curl -O https://raw.githubusercontent.com/petre0013/www.doc/main/docker-compose.tls.yml
   ```
4. Start the stack with only this file:
   ```bash
   docker compose -f docker-compose.tls.yml up -d
   ```
   After changing `APP_URL` or `TLS_HOSTS` later, apply it with
   `docker compose -f docker-compose.tls.yml up -d --force-recreate`.

The certificate is self-signed, so browsers warn on first visit; this is safe
to bypass on your own network. To remove the warning, trust Caddy's root CA on
each device (on a Windows domain, push it via Group Policy → *Trusted Root
Certification Authorities*):

```bash
docker compose -f docker-compose.tls.yml cp caddy:/data/caddy/pki/authorities/local/root.crt ./wwwdoc-root.crt
```

> **`ERR_SSL_PROTOCOL_ERROR`?** Either the name in the address bar isn't in
> `APP_URL`/`TLS_HOSTS` (see step 2), or you're browsing `https://` to the
> plain-HTTP stack from Option A (port 8080 has no TLS).

## 4. Run the setup wizard

First boot takes a minute or two while the containers run database migrations
and build caches. Check progress with:

```bash
docker compose -f docker-compose.prod.yml ps        # all services "running"
docker compose -f docker-compose.prod.yml logs -f app   # watch startup; Ctrl-C to exit
```

Then open your `APP_URL` in a browser and the setup wizard walks through:

1. The initial admin account.
2. The instance name.
3. SMTP settings for outgoing email.

Before adding real content, configure backups under **Settings > Backups**. For
production, point them at an SMB share on a different machine — local disk is
lost together with the host. See the [Backups & Encryption Guide](backups.md).

---

## Updating

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

For the internal TLS option, swap `docker-compose.prod.yml` for
`docker-compose.tls.yml`. Schema migrations run automatically on boot.

---

## GUI managers (Komodo, Portainer, Dockge)

- Create a stack pointing at the Git repository, or paste the contents of
  `docker-compose.prod.yml` (or `docker-compose.tls.yml` for internal TLS)
  into the UI.
- Add your `.env` variables (`APP_URL`, `DB_PASSWORD`, …) in the manager's
  environment section.

---

## Building from source (optional)

To compile the images yourself instead of pulling them, clone the repo and add
the `docker-compose.build.yml` override:

```bash
git clone https://github.com/petre0013/www.doc.git && cd www.doc
cp .env.example .env   # configure as in step 2
docker compose -f docker-compose.prod.yml -f docker-compose.build.yml up -d --build
```

The override adds `build:` blocks for the `app` and `web` images; `worker` and
`scheduler` reuse the built `app` image. It combines with the TLS variant the
same way (`-f docker-compose.tls.yml -f docker-compose.build.yml`). To update,
`git pull` and re-run the `up -d --build` command.
