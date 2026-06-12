# Data Model: Deployment Topology

No database schema changes. The "entities" here are the deployment building blocks — services, images,
volumes, networks — and the configuration/secret inventory that parameterizes them.

## Services

| Service | Image source | Published port | Internal port | Depends on (condition) | Healthcheck |
| --- | --- | --- | --- | --- | --- |
| `web` | build `docker/nginx/Dockerfile` (on `nginx:1.31-trixie-perl`) | `${HTTP_PORT}:80` | 80 | `serve` (healthy), `websocket` (started) | `GET /up` |
| `serve` | build `Dockerfile` target `fpm` (on `php:8.4.22-alpine`) | — | 9000 (FastCGI) | `backend` (completed_successfully) | php-fpm ping `:9000` |
| `queue` | build target `queue` | — | — | `backend` (completed_successfully) | worker liveness |
| `websocket` | build target `reverb` | — | 8080 | `backend` (completed_successfully) | TCP `:8080` |
| `backend` | build target `init` | — | — | `database` (healthy) | none (one-shot, gated by exit 0) |
| `database` | `postgres:19` (unmodified) | — (internal only) | 5432 | — | `pg_isready` |

- `restart`: `unless-stopped` for the four long-running services + `database`; `backend` uses `restart: "no"`
  (run-once).
- All services share one user-defined bridge network; only `web` publishes a host port (single ingress, R4).

## Images

| Image | Base (pinned) | Build stages | Ships | Non-root |
| --- | --- | --- | --- | --- |
| PHP services (`fpm`/`queue`/`reverb`/`init`) | `php:8.4.22-alpine` | `vendor` (composer:2.8) → `assets` (node:22-alpine) → `runtime` → target | vendor, app code, built `public/build`, `pdo_pgsql`, opcache | yes (`www`/non-root) |
| `web` | `nginx:1.31-trixie-perl` | copies built `public/` from the `assets` stage/context + `default.conf` | static assets + nginx config | runs as non-root where the base allows |
| `database` | `postgres:19` | — (not built) | stock | per base image |

Build-only images (`composer:2.8`, `node:22-alpine`) never appear in a shipped layer.

## Volumes

| Volume | Mounted by | Purpose | Lifecycle |
| --- | --- | --- | --- |
| `pgdata` (named) | `database` → `/var/lib/postgresql/data` | durable DB state (app data + sessions + queue + cache) | survives stop/restart/recreate (FR-008) |

No application volumes — app containers are stateless (logs → stderr; assets baked in).

## Configuration inventory (non-secret — via `env_file` `.env.production`)

| Variable | Value / note |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | public base URL (browser-facing) |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `database` (service name) |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | app database name (matches `POSTGRES_DB`) |
| `DB_USERNAME` | app db user (matches `POSTGRES_USER`) |
| `SESSION_DRIVER` / `QUEUE_CONNECTION` / `CACHE_STORE` | `database` (already the app default) |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID` | reverb app id (non-secret) |
| `REVERB_APP_KEY` | reverb **public** key (also baked into assets as `VITE_REVERB_APP_KEY`) |
| `REVERB_HOST` | `websocket` (internal, for server-side); browser uses same-origin via nginx |
| `REVERB_PORT` / `REVERB_SCHEME` | `8080` / `http` (internal) |
| `OPENSTREET_API_URL` / `OPENSTREET_ROUTE_URL` | external endpoints |
| `OPENSTREET_API_TIMEOUT` / `_CONNECT_TIMEOUT` / `_RETRIES` / `_JOB_TIMEOUT` | as in `.env.example` (long-job safe) |
| `OPENSTREET_MODE` | `trucking` |
| `DB_QUEUE_RETRY_AFTER` | `1320` (> worker timeout ≥ job timeout) |
| `HTTP_PORT` | host port for the `web` ingress |

## Secret inventory (NOT env, NOT baked — Docker secrets, `*_FILE`)

| Secret | Consumed as | By |
| --- | --- | --- |
| `app_key` | `APP_KEY_FILE` → `APP_KEY` (entrypoint shim) | all PHP services |
| `db_password` | `DB_PASSWORD_FILE` → `DB_PASSWORD` (PHP); `POSTGRES_PASSWORD_FILE` (postgres native) | PHP services + `database` |
| `openstreet_api_key` | `OPENSTREET_API_KEY_FILE` → `OPENSTREET_API_KEY` | `serve`, `queue` |
| `reverb_app_secret` | `REVERB_APP_SECRET_FILE` → `REVERB_APP_SECRET` | `serve`, `queue`, `websocket` |

## Constraints / business rules

- **CR-1 (pinning)**: every image reference MUST be an exact, immutable tag — no `latest`/floating (R1).
- **CR-2 (no secrets in images)**: no secret may appear in a build arg, `ENV`, or any image layer; secrets enter
  only at run time via mounted files (R5, FR-011).
- **CR-3 (ordered readiness)**: app services MUST NOT start until `backend` (migrations/caches) has completed
  successfully, and `backend` MUST NOT run until `database` is healthy (FR-007, FR-009).
- **CR-4 (durability)**: only `pgdata` is durable; recreating any app container MUST NOT lose data (FR-008).
- **CR-5 (fail-fast)**: a missing required var/secret MUST abort the affected service with a message naming it
  (FR-015), never a silent half-boot.
- **CR-6 (portability)**: the same images MUST run in another environment by changing only `.env.production` +
  the secret files — no rebuild (FR-012); only the public Reverb key is baked.
