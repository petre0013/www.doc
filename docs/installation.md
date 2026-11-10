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

#### Example: Nginx Proxy Manager with an internal wildcard certificate

[Nginx Proxy Manager](https://nginxproxymanager.com) (NPM) gives the proxy a
web UI. Its built-in certificates come from Let's Encrypt, which can't issue
for internal-only names, so you create one wildcard certificate for all apps
from your own CA and upload it once. Replace `corp.lan` with your domain; it
needs two labels, as browsers reject a wildcard directly under a top-level
domain (`*.lan`).

1. **Deploy NPM** as its own stack. It owns ports 80/443 (free them first) and
   creates the shared `proxy` network:

   ```yaml
   name: proxy
   services:
     npm:
       image: jc21/nginx-proxy-manager:latest
       restart: unless-stopped
       ports: ["80:80", "443:443", "81:81"]   # 81 = admin web UI
       networks: [proxy]
       volumes: [npm_data:/data, npm_letsencrypt:/etc/letsencrypt]
   networks:
     proxy:
       name: proxy
   volumes:
     npm_data: {}
     npm_letsencrypt: {}
   ```

   Open `http://<server-ip>:81` and create the admin account (older versions
   start with `admin@example.com` / `changeme`; change it). Port 81 controls
   every site, so allow only admin machines to reach it.

2. **Create the CA and wildcard certificate** on any machine with `openssl`:

   ```bash
   DOMAIN=corp.lan

   # Root CA, valid 10 years: the certificate devices will trust
   openssl req -x509 -new -nodes -newkey rsa:4096 -sha256 -days 3650 \
     -keyout corp-root-ca.key -out corp-root-ca.crt -subj "/CN=Corp Internal Root CA" \
     -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign"

   # Wildcard certificate, valid 825 days (the longest Apple devices accept)
   openssl req -new -nodes -newkey rsa:2048 -keyout wildcard.key -out wildcard.csr -subj "/CN=*.$DOMAIN"
   printf "subjectAltName=DNS:*.$DOMAIN,DNS:$DOMAIN\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n" > wildcard.ext
   openssl x509 -req -in wildcard.csr -CA corp-root-ca.crt -CAkey corp-root-ca.key -CAcreateserial \
     -out wildcard.crt -days 825 -sha256 -extfile wildcard.ext
   ```

   Keep `corp-root-ca.key` offline: anyone holding it can create certificates
   your devices trust. You only need it to renew the wildcard certificate
   (re-run the last three commands before it expires).

   In NPM, go to **SSL Certificates → Add → Custom** and upload `wildcard.key`
   and `wildcard.crt`.

3. **Deploy www.doc** with `docker-compose.proxy.yml` as described above, with
   `APP_URL=https://docs.corp.lan`.

4. **Add a Proxy Host** in NPM:
   - *Details*: domain `docs.corp.lan`, scheme `http`, forward hostname
     `wwwdoc-web`, port `80`, *Block Common Exploits* on.
   - *SSL*: the wildcard certificate, *Force SSL* and *HTTP/2* on.
   - *Advanced*: the line below. **Required:** without it nginx's 1 MB default
     rejects backup imports and document uploads with `413`.

     ```nginx
     client_max_body_size 1088m;
     ```

   NPM already forwards `Host`, `X-Forwarded-For` and `X-Forwarded-Proto`.

5. **DNS and devices**: add an A record for each name pointing at the server,
   and install `corp-root-ca.crt` as a trusted root on each device (Windows:
   Group Policy → *Trusted Root Certification Authorities*, or
   `certutil -addstore -f Root corp-root-ca.crt`; macOS: Keychain Access →
   *Always Trust*; Linux: copy to `/usr/local/share/ca-certificates/` and run
   `update-ca-certificates`; Firefox: enable `security.enterprise_roots.enabled`
   or import it). It covers every app under `*.corp.lan`.

Other apps join the same way: drop their published port, add their web service
to the external `proxy` network with a unique alias, and add a Proxy Host
pointing at that alias and the port inside the container.

> **Troubleshooting:** `502` means NPM can't reach the app (is it on the
> `proxy` network, and do the hostname and port match?). `413` means the
> *Advanced* line is missing. A certificate warning means that device doesn't
> trust the root CA yet.

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
