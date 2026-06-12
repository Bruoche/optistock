# Quickstart: Containerized Deployment

Build the images, supply configuration + secrets, start the whole stack with one command, and verify.

## Prerequisites

- A host with Docker + the Compose plugin. No PHP/Node/Postgres needed on the host.
- Outbound network access to the external route-optimization API.

## 1 — Configuration (non-secret)

1. `cp .env.production.example .env.production` and set: `APP_URL`, `HTTP_PORT`, `DB_DATABASE`, `DB_USERNAME`,
   `REVERB_APP_ID`, `REVERB_APP_KEY` (public), and the `OPENSTREET_*` endpoints. Leave `APP_ENV=production`,
   `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `DB_CONNECTION=pgsql`, `DB_HOST=database`.
2. Confirm no secret values are present in this file (secrets go in step 2).

## 2 — Secrets (never committed)

Create one file per secret under `docker/secrets/` (these are gitignored; `*.example` show the format):

```
docker/secrets/app_key              # e.g. base64:...  (php artisan key:generate --show)
docker/secrets/db_password
docker/secrets/openstreet_api_key
docker/secrets/reverb_app_secret
```

Set restrictive permissions (`chmod 600 docker/secrets/*`).

## 3 — Build + start (one command)

```bash
docker compose up -d --build
```

- `database` comes up → `pg_isready` healthy.
- `backend` (init) runs migrations + cache warming, then exits 0.
- `serve` / `queue` / `websocket` start and go healthy.
- `web` (nginx) goes healthy on `${HTTP_PORT}`.

## 4 — Verify healthy (US1, SC-001)

```bash
docker compose ps        # every long-running service: healthy; backend: exited (0)
docker compose logs backend   # shows migrations ran
curl -fsS http://localhost:${HTTP_PORT}/up   # Laravel health route -> 200
```

Open `http://localhost:${HTTP_PORT}` → the application loads.

## 5 — End-to-end across containers (US4, SC-003)

1. Log in, place stops, start an optimization that queues.
2. **Expect**: `queue` logs show the job processed; the result appears in the browser in real time (broadcast
   via `websocket`, proxied by `web`) — no manual refresh.

## 6 — Data persists across restart (US2, SC-004)

```bash
docker compose down          # keeps the named volume
docker compose up -d
```

- Data created before is still present; the schema is **not** re-initialized destructively.
- First-ever start against an empty DB auto-migrates (US2 scenario 2).

## 7 — Secret hygiene + portability (US3, SC-005, SC-002)

- `docker history <php-image>` and `docker inspect` reveal **no** secret values.
- Missing a required secret/var → the affected service exits with a message naming it (SC-006); fix and re-up.
- Re-deploy elsewhere by changing only `.env.production` + `docker/secrets/*` — no rebuild (SC-002).

## Teardown

```bash
docker compose down            # stop + remove containers/network (keeps pgdata)
docker compose down -v         # also remove the database volume (DESTROYS data)
```
