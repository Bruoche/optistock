# Contract: Compose Stack (`docker-compose.yml`)

One file, one `docker compose up`, the whole stack (FR-001). Services, dependency conditions, ports, secrets,
and volumes below. Pinned images only (CR-1).

## Service contract

```yaml
services:
  database:        # postgres:18
    image: postgres:18
    environment:
      POSTGRES_DB: ${DB_DATABASE}
      POSTGRES_USER: ${DB_USERNAME}
      POSTGRES_PASSWORD_FILE: /run/secrets/db_password   # native *_FILE (R5)
    secrets: [db_password]
    volumes: [pgdata:/var/lib/postgresql/data]           # durable (CR-4)
    healthcheck:                                         # pg_isready (FR-013)
      test: ["CMD-SHELL", "pg_isready -U $${DB_USERNAME} -d $${DB_DATABASE}"]
    restart: unless-stopped

  backend:         # build target init — run once
    build: { context: ., target: init }
    env_file: [.env.production]
    secrets: [app_key, db_password, openstreet_api_key, reverb_app_secret]
    environment:                                         # *_FILE point at mounted secrets
      APP_KEY_FILE: /run/secrets/app_key
      DB_PASSWORD_FILE: /run/secrets/db_password
      OPENSTREET_API_KEY_FILE: /run/secrets/openstreet_api_key
      REVERB_APP_SECRET_FILE: /run/secrets/reverb_app_secret
    depends_on: { database: { condition: service_healthy } }   # FR-009
    restart: "no"                                        # one-shot (CR-3)

  serve:           # build target fpm
    build: { context: ., target: fpm }
    env_file: [.env.production]
    secrets: [app_key, db_password, openstreet_api_key, reverb_app_secret]
    environment: { APP_KEY_FILE: ..., DB_PASSWORD_FILE: ..., OPENSTREET_API_KEY_FILE: ..., REVERB_APP_SECRET_FILE: ... }
    depends_on: { backend: { condition: service_completed_successfully } }   # CR-3
    healthcheck: { test: ["CMD", "/usr/local/bin/healthcheck.sh", "fpm"] }
    restart: unless-stopped

  queue:           # build target queue  (default + broadcasts queues)
    build: { context: ., target: queue }
    env_file: [.env.production]
    secrets: [app_key, db_password, openstreet_api_key, reverb_app_secret]
    environment: { APP_KEY_FILE: ..., DB_PASSWORD_FILE: ..., OPENSTREET_API_KEY_FILE: ..., REVERB_APP_SECRET_FILE: ... }
    depends_on: { backend: { condition: service_completed_successfully } }
    healthcheck: { test: ["CMD", "/usr/local/bin/healthcheck.sh", "queue"] }
    restart: unless-stopped

  websocket:       # build target reverb
    build: { context: ., target: reverb }
    env_file: [.env.production]
    secrets: [app_key, db_password, reverb_app_secret]
    environment: { APP_KEY_FILE: ..., DB_PASSWORD_FILE: ..., REVERB_APP_SECRET_FILE: ... }
    depends_on: { backend: { condition: service_completed_successfully } }
    healthcheck: { test: ["CMD", "/usr/local/bin/healthcheck.sh", "reverb"] }
    restart: unless-stopped

  web:             # build docker/nginx/Dockerfile — single ingress
    build: { context: ., dockerfile: docker/nginx/Dockerfile, args: { VITE_REVERB_APP_KEY: ${REVERB_APP_KEY} } }
    ports: ["${HTTP_PORT}:80"]
    depends_on:
      serve: { condition: service_healthy }
      websocket: { condition: service_started }
    healthcheck: { test: ["CMD", "wget", "-qO-", "http://127.0.0.1/up"] }
    restart: unless-stopped

secrets:
  app_key:            { file: ./docker/secrets/app_key }
  db_password:        { file: ./docker/secrets/db_password }
  openstreet_api_key: { file: ./docker/secrets/openstreet_api_key }
  reverb_app_secret:  { file: ./docker/secrets/reverb_app_secret }

volumes:
  pgdata:
```

## Requirements

- **C-1**: A single `docker compose up -d` starts every service; no manual per-service steps (FR-001, SC-001).
- **C-2**: `web` is the **only** service publishing a host port (`${HTTP_PORT}:80`); it proxies PHP → `serve`
  and the websocket → `websocket` (R4). No other service is reachable from the host.
- **C-3**: Startup order is enforced by conditions: `database healthy` → `backend completed_successfully` →
  `serve`/`queue`/`websocket` → `web healthy` (CR-3, FR-007, FR-009).
- **C-4**: Secrets are file-backed (`secrets:` top-level) and referenced by services; **no** secret appears in
  `environment:` as a literal value (only the `*_FILE` *paths* do) (CR-2, FR-011).
- **C-5**: Only `pgdata` is a named volume; recreating app containers preserves data (CR-4, FR-008, SC-004).
- **C-6**: All `image:`/base references are exact pinned tags (CR-1).
- **C-7**: Bringing the stack down and up again returns to healthy with no manual repair (FR-017).

## Notes

- `${...}` non-secret values resolve from `.env.production` (env_file) and the shell/`.env` used by compose for
  interpolation; secrets resolve from `./docker/secrets/*` (gitignored; `*.example` templates are committed).
- `web` depends on `websocket: service_started` (not healthy) to avoid a cyclic readiness wait; the WS proxy
  tolerates a brief warm-up, and the app's existing poll fallback covers the gap (spec US4 scenario 2).
