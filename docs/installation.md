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
| `APP_KEY` | *(Recommended)* A 32-byte base64 string (`base64:…`), generated with `echo "base64:$(openssl rand -base64 32)"`. It encrypts sessions and the stored SMTP / backup-destination passwords. Leave blank and the app generates one on first boot and keeps it in the storage volume — but then losing that volume also loses the key, so setting it here (and keeping a copy) is safer. |
| `BACKUP_ENCRYPTION_KEY` | *(Optional)* Enables encrypted backups. Generate with `openssl rand -base64 32`. **Store it somewhere safe and separate** — an encrypted backup cannot be restored without it. Leave blank for unencrypted backups. |

Email (`MAIL_*`) is not configured here — the setup wizard handles it after boot.

## 3. Start the app

Starts the app on port `8080` over HTTP:

```bash
docker compose -f docker-compose.prod.yml up -d
```

That's all you need on a trusted network. For HTTPS, or to share the server
with other web apps, put your own reverse proxy in front.

### Behind a reverse proxy container (same Docker host)

For HTTPS with internal DNS names, and several apps sharing one IP: run one
proxy container (Caddy, Nginx Proxy Manager, Traefik) that owns ports 80/443
and routes each hostname to its app. Use `docker-compose.proxy.yml` instead of
the prod file. It is self-contained (paste it into Komodo as-is), publishes no
port, and joins your proxy's Docker network, where the app is `wwwdoc-web:80`.

```bash
curl -O https://raw.githubusercontent.com/petre0013/www.doc/main/docker-compose.proxy.yml
```

In `.env`, set `APP_URL` to the HTTPS address (e.g. `https://docs.corp.lan`)
plus `DB_PASSWORD`/`APP_KEY` as above. If your proxy's network isn't called
`proxy`, set `PROXY_NETWORK`. The network must exist first (your proxy stack
creates it, or run `docker network create proxy` once). Secure cookies and the
real client IP are preset; nothing else is needed.

```bash
docker compose -f docker-compose.proxy.yml up -d
```

A Caddy proxy with its own internal CA needs just this per app:

```
docs.corp.lan {
    tls internal
    reverse_proxy wwwdoc-web:80
}
```

Point each name's DNS record at the server. Trust Caddy's root certificate
(`/data/caddy/pki/authorities/local/root.crt` in the Caddy container) once on
each device and every app behind it is trusted.

### Behind a proxy on the host or another machine

Use `docker-compose.prod.yml` and set these in `.env`:

```bash
APP_URL=https://docs.example.com
WEB_PORT=127.0.0.1:8081        # pick a free port; loopback keeps the proxy as the only way in
REAL_IP_FROM=172.16.0.0/12     # Docker's private range, where the proxy appears to come from
SESSION_SECURE_COOKIE=true     # HTTPS only
```

Without `REAL_IP_FROM`, every user appears to come from the proxy's IP in the
audit log and shares one login throttle. Only trust a Docker range when
`WEB_PORT` is bound to loopback: on a port open to the network, visitors could
forge their IP. If the proxy runs on another machine, publish the port normally
and set `REAL_IP_FROM` to that machine's IP.

### Proxy requirements

The proxy must forward `Host`, `X-Forwarded-For` and `X-Forwarded-Proto`, and
must allow uploads of up to 1088 MB (the backup import limit). Caddy does all of
this by default — see [`Caddyfile.example`](../Caddyfile.example). For Nginx:

```nginx
server {
    listen 443 ssl;
    server_name docs.example.com;
    # ssl_certificate / ssl_certificate_key ...

    client_max_body_size 1088m;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## 4. Run the setup wizard

First boot takes a minute or two while the containers run database migrations
and build caches. Check progress with:

```bash
docker compose -f docker-compose.prod.yml ps        # all services "running"
docker compose -f docker-compose.prod.yml logs -f app   # watch startup; Ctrl-C to exit
```

(Swap in `docker-compose.proxy.yml` here and below if you use that file.)

Then open your `APP_URL` in a browser and the setup wizard walks through the
steps below.

> **Do this straight away.** Until the wizard is finished, *whoever reaches the
> site first* creates the admin account. Complete it right after the first
> boot, before the address is shared or reachable by others.

The wizard covers:

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

Schema migrations run automatically on boot.

The compose files use the `latest` tag, which moves only when a new
[release](https://github.com/petre0013/www.doc/releases) is published — so the
two commands above always take you to the newest release. To stay on a fixed
version instead, replace `latest` with a release tag (e.g. `v1.0.0`) on both
`image:` lines (`app` and `web`); keep the two on the same version.

---

## GUI managers (Komodo, Portainer, Dockge)

- Create a stack pointing at the Git repository, or paste the contents of
  `docker-compose.prod.yml` into the UI — or `docker-compose.proxy.yml` when
  a reverse proxy container on the same host serves it.
- Add your `.env` variables (`APP_URL`, `DB_PASSWORD`, …) in the manager's
  environment section.
- **Automatic updates (Komodo):** with the default `latest` tags, enable
  *Poll for updates* and *Auto update* on the stack — Komodo redeploys when a
  new release is published. This does not work with a pinned tag like
  `v1.0.0`, which never changes.

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
`scheduler` reuse the built `app` image. To update, `git pull` and re-run the
`up -d --build` command.
