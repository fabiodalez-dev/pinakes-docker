<p align="center">
  <img src="https://raw.githubusercontent.com/fabiodalez-dev/Pinakes/main/public/assets/brand/logo.png" alt="Pinakes" width="360">
</p>

# Pinakes — Docker image

Production-ready, single-container Docker image for **[Pinakes](https://github.com/fabiodalez-dev/Pinakes)**, the self-hosted Integrated Library System (ILS) for schools, municipalities, and private collections.

[![Smoke test](https://github.com/fabiodalez-dev/pinakes-docker/actions/workflows/smoke-test.yml/badge.svg)](https://github.com/fabiodalez-dev/pinakes-docker/actions/workflows/smoke-test.yml)
[![Build & Publish](https://github.com/fabiodalez-dev/pinakes-docker/actions/workflows/build-publish-docker.yml/badge.svg)](https://github.com/fabiodalez-dev/pinakes-docker/actions/workflows/build-publish-docker.yml)
[![Docker Hub](https://img.shields.io/docker/v/fabiodalez/pinakes?label=Docker%20Hub&logo=docker)](https://hub.docker.com/r/fabiodalez/pinakes)

- **One image, Apache + PHP 8.2** (mod_php) — no separate nginx/fpm container, mirrors the upstream "Apache-only" production target.
- **Built from the official release ZIP** (which already ships a production `vendor/`), so the running image is byte-for-byte the artifact end users deploy — no source duplication, no Composer at build time.
- **Headless install** — set `ADMIN_EMAIL` + `ADMIN_PASSWORD` and the container installs Pinakes (schema, locale data, bundled plugins, admin user) on first boot, **no web wizard**. Omit them and the container creates an empty database and redirects every request to the full web wizard at `/installer/` until the install is completed.
- **Auto-tracks upstream** — a new Pinakes release automatically rebuilds and republishes this image.
- Published to **GitHub Container Registry** (`ghcr.io`) and, optionally, **Docker Hub**.

> This image bundles Pinakes, which is licensed **GPL-3.0**. See [Attribution & license](#attribution--license).

---

## Screenshots

| Public catalog (OPAC) | Browse & filter |
|---|---|
| [![Home](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/01-opac-home.png)](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/01-opac-home.png) | [![Catalogue](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/02-catalogue.png)](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/02-catalogue.png) |

| Admin dashboard | Collection management |
|---|---|
| [![Dashboard](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/03-admin-dashboard.png)](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/03-admin-dashboard.png) | [![Books](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/04-admin-books.png)](https://raw.githubusercontent.com/fabiodalez-dev/pinakes-docker/main/docs/screenshots/04-admin-books.png) |

---

## Quick start

```bash
git clone https://github.com/fabiodalez-dev/pinakes-docker.git
cd pinakes-docker
cp .env.example .env
# edit .env: set ADMIN_EMAIL + ADMIN_PASSWORD (headless), strong DB passwords,
# and a stable PLUGIN_ENCRYPTION_KEY (printf 'base64:%s\n' "$(openssl rand -base64 32)")
docker compose up -d
```

Open <http://localhost:8080> and log in with the admin credentials you set. That's it.

> Using only `docker compose up -d` pulls the published image. Add `--build` to build it locally from the `Dockerfile`.

### Run the image directly (external database)

```bash
docker run -d --name pinakes -p 8080:80 \
  -e DB_HOST=mydb.example.com -e DB_NAME=pinakes \
  -e DB_USER=pinakes -e DB_PASS='strong-pass' \
  -e ADMIN_EMAIL=admin@example.com -e ADMIN_PASSWORD='strong-admin-pass' \
  -e PLUGIN_ENCRYPTION_KEY="base64:$(openssl rand -base64 32)" \
  -v pinakes_storage:/var/www/html/storage \
  -v pinakes_uploads:/var/www/html/public/uploads \
  fabiodalez/pinakes:latest
```

> **Images:** [`fabiodalez/pinakes`](https://hub.docker.com/r/fabiodalez/pinakes) on Docker Hub (public) and `ghcr.io/fabiodalez-dev/pinakes-docker` on GHCR. Tags: `latest` and each Pinakes version (e.g. `0.7.22`). Multi-arch: `linux/amd64` + `linux/arm64`.

---

## Configuration

All settings are environment variables (the entrypoint writes the app's `.env` from them on first boot).

| Variable | Default | Purpose |
|---|---|---|
| `DB_HOST` | `db` | MySQL host (compose service name). |
| `DB_PORT` | `3306` | MySQL port. |
| `DB_NAME` | `pinakes` | Database name (created automatically if missing). |
| `DB_USER` / `DB_PASS` | `pinakes` / `pinakes` | DB credentials. **Change for production.** |
| `DB_SOCKET` | _(empty)_ | Optional unix socket (takes precedence over host/port). |
| `APP_ENV` | `production` | `production` or `development`. |
| `APP_LOCALE` | `it_IT` | Seed/UI language: `it_IT`, `en_US`, `de_DE`, `fr_FR`. |
| `APP_CANONICAL_URL` | _(empty)_ | Canonical URL for emails/redirects/robots. Set this behind a reverse proxy. |
| `APP_DEBUG` / `DISPLAY_ERRORS` | `false` / `false` | Keep `false` in production. |
| `FORCE_HTTPS` | `false` | Enforce HTTPS/HSTS (TLS detected via `X-Forwarded-Proto`). |
| `SESSION_LIFETIME` | `3600` | Session lifetime (seconds). |
| `PLUGIN_ENCRYPTION_KEY` | auto | `base64:<32-byte key>` for encrypted plugin settings. **Set a stable value** so secrets survive container recreation. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | _(empty)_ | Set **both** for a fully headless install (skips the wizard). |
| `ADMIN_NAME` / `ADMIN_SURNAME` | `Admin` / `User` | Admin display name. |

### Headless vs. wizard

- **Both `ADMIN_EMAIL` and `ADMIN_PASSWORD` set** → the container imports the schema, locale data, optimisation indexes, default settings and bundled plugins (including the default-active **Mobile API**), creates the admin user, and writes the `.installed` lock. Pinakes is ready — the installer route then shows _"Already installed"_.
- **Either missing** → everything except the admin user is prepared; you finish the single admin step at `/installer/`.

The install is **idempotent**: on every subsequent boot it detects `.installed` and skips straight to serving.

---

## Persistence

Three things must outlive the container:

| Path | What |
|---|---|
| `db_data` → `/var/lib/mysql` | the database |
| `storage` → `/var/www/html/storage` | logs, cache, backups, sessions, plugin state |
| `uploads` → `/var/www/html/public/uploads` | book covers, author images, digital assets |

The provided `docker-compose.yml` wires named volumes for all three. Also keep a **stable `PLUGIN_ENCRYPTION_KEY`** (in `.env`) so encrypted plugin settings remain readable after a recreate.

---

## Updating

The recommended way to update on Docker is to **move the container to the new image** — your data lives in the database and in the `storage`/`uploads` volumes, so it survives the swap:

```bash
# pin a version
PINAKES_TAG=0.7.33 docker compose pull && docker compose up -d
# or always-latest
docker compose pull && docker compose up -d
```

On boot the entrypoint **applies any pending database migrations automatically** (it drives the app's own migration runner, tracking the applied schema version in `system_settings`), so an image pull is a *complete* upgrade — code and schema. It no-ops in milliseconds when the schema is already current. Knobs:

| Env var | Purpose |
|---|---|
| `PINAKES_MIGRATE_FROM` | One-shot override of the starting schema version (only needed if you imported a pre-0.7.22 database dump). |
| `PINAKES_MIGRATE_STRICT` | Set to `1` to make a failed migration abort the container start (default: log loudly and boot anyway). |

Existing installs created by older images have no recorded schema version yet: the first boot assumes `0.7.22` (the first published image) and re-applies the — idempotent — migrations since then, healing any drift accumulated by past image-pull upgrades. Avoid manual file changes inside the container: anything written to the container layer is lost when the container is recreated.

The **in-app updater** (Admin → Updates) also works inside the container (the code dir is writable and OPcache revalidates timestamps), but its changes only live in the container layer until you next move to the matching image.

This image **auto-tracks upstream**: every Pinakes release triggers a rebuild here (see below), so `:latest` follows the newest stable Pinakes shortly after it ships.

---

## How auto-update works

```
Pinakes create-release.sh ──repository_dispatch(pinakes_release)──▶ build-publish-docker.yml ──▶ ghcr.io / Docker Hub
                                                          (daily cron safety-net poller backs this up)
```

- **`build-publish-docker.yml`** — builds multi-arch (`amd64` + `arm64`) from the release ZIP for the requested version and pushes `:<version>` + `:latest`. Fires on the upstream dispatch, a manual `workflow_dispatch`, or a `v*` tag here. It verifies the release ZIP exists before building.
- **`auto-update-on-release.yml`** — records the upstream version in `.latest-pinakes-version` and, on its daily cron, triggers a build if a newer Pinakes release appeared without a dispatch.
- **`smoke-test.yml`** — builds the image and runs `tests/docker-smoke.sh` (full headless-install + HTTP/extension/DB assertions) on every push/PR, so a broken image is never published.

---

## Publishing setup (one-time, for maintainers)

GHCR works out of the box (the built-in `GITHUB_TOKEN`). To **also** publish to Docker Hub, add two repository secrets (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `DOCKERHUB_USERNAME` | your Docker Hub username |
| `DOCKERHUB_TOKEN` | a Docker Hub access token with **Read/Write** scope ([hub.docker.com → Account → Security](https://hub.docker.com/settings/security)) |

Without them, the workflow publishes to GHCR only (and says so).

To let upstream Pinakes trigger rebuilds automatically, add the snippet in [`create-release-snippet.md`](create-release-snippet.md) to Pinakes' `scripts/create-release.sh`.

---

## Building locally

```bash
docker build --build-arg PINAKES_VERSION=0.7.22 -t pinakes:0.7.22 .
PINAKES_VERSION=0.7.22 tests/docker-smoke.sh   # build + full smoke test
```

---

## Security notes

- Set strong `DB_PASS` / `DB_ROOT_PASS` and a stable `PLUGIN_ENCRYPTION_KEY` for production.
- Terminate TLS at a reverse proxy and forward `X-Forwarded-Proto`; set `APP_CANONICAL_URL` and `FORCE_HTTPS=true`.
- The application internals (`app/`, `config/`, `installer/`, `storage/`, `vendor/`) live outside the Apache `DocumentRoot` (`public/`) and are additionally denied by vhost rules.
- `display_errors`/`expose_php` are off; uploads are capped at 512 MB.

---

## Attribution & license

This repository (the Docker packaging) and the bundled Pinakes application are licensed **GPL-3.0**. Pinakes is developed at **[fabiodalez-dev/Pinakes](https://github.com/fabiodalez-dev/Pinakes)**; please refer there for application documentation, issues, and support. This image is an independent packaging effort — report image-specific problems in this repository's issue tracker.
