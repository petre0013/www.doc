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
- Admin / editor / viewer roles and an audit log
- Scheduled, optionally encrypted backups (local disk or SMB)

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

Want HTTPS without a reverse proxy, or deploying with Komodo / Portainer?
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
