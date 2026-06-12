---
description: "Task list for Containerized Deployment with a Single Compose Stack (008)"
---

# Tasks: Containerized Deployment with a Single Compose Stack

**Input**: Design documents from `/specs/008-containerized-deployment/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/images.md, contracts/compose-services.md

**Tests**: This is infrastructure — no application unit tests are added. Verification is the `quickstart.md`
walkthrough plus the compose healthchecks (each user story below ends with a concrete verification task).

**Scope**: New deployment files, plus **one small front-end change** — `resources/js/lib/echo.ts` gains a
same-origin websocket fallback so the container image carries no per-environment host (F1). `pgsql` is already
supported by `config/database.php`, so switching engines is configuration (`DB_CONNECTION=pgsql`).

**Organization**: Four user stories — US1 (one-command bring-up, P1, MVP), US2 (data/schema persistence, P2),
US3 (env config + secret hygiene, P3), US4 (background + realtime across containers, P3). Most build work is a
shared foundation (images, entrypoint, nginx); each story then wires/verifies its slice.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 / US2 / US3 / US4

## Path Conventions

Repo root holds `Dockerfile`, `docker-compose.yml`, `.dockerignore`; supporting files under `docker/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Scaffolding + config templates the images and compose stack consume. No app code.

- [X] T001 [P] Create `.dockerignore` at repo root excluding `vendor/`, `node_modules/`, `.git/`, `tests/`, local `.env*`, `docker/secrets/*` (keep `*.example`), and build artefacts, so the build context stays small and no secret/local file leaks into an image layer (CR-2).
- [X] T002 [P] Create `.env.production.example` with the non-secret config matrix from `data-model.md` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `HTTP_PORT`, `LOG_CHANNEL=stderr`, `DB_CONNECTION=pgsql`, `DB_HOST=database`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_HOST=websocket`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, all `OPENSTREET_*` endpoints/timeouts, `OPENSTREET_MODE=trucking`, `DB_QUEUE_RETRY_AFTER=1320`) — **no secret values**.
- [X] T003 [P] Create `docker/secrets/{app_key,db_password,openstreet_api_key,reverb_app_secret}.example` placeholder files and add `docker/secrets/*` (excluding `*.example`) and `.env.production` to `.gitignore`, so real secrets are never committed (CR-2, FR-011).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The buildable images + entrypoint + nginx config every service depends on. **Blocks all user
stories.** Pinned bases only (CR-1); UBI-clean, layer-ordered (D7); secrets never enter the build (CR-2).

- [X] T004 Create the multi-stage `Dockerfile` `vendor` stage (`FROM composer:2.8`): **no DB build tools** (composer `--no-scripts` doesn't need `pdo_pgsql`), `COPY composer.json composer.lock` **before** source, `composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader`, then `COPY . .` + `composer dump-autoload --optimize --classmap-authoritative` (contracts/images.md I-5, D7).
- [X] T005 Add the `Dockerfile` `assets` stage (`FROM node:22-alpine`): `ARG VITE_REVERB_APP_KEY` (public only), `COPY package.json package-lock.json` before source, `npm ci`, `COPY . .`, `npm run build` → `public/build` (I-2).
- [X] T006 Add the `Dockerfile` `ext` stage (`FROM php:8.4.22-alpine` — **same base as runtime** so the ABI matches, F3): `apk add --virtual .build-deps $PHPIZE_DEPS postgresql-dev`, `docker-php-ext-install pdo_pgsql`. Then the `runtime` stage (`FROM php:8.4.22-alpine`): `apk add --no-cache libpq` (runtime lib only), `COPY --from=ext` the `pdo_pgsql.so` + enable it, opcache (`validate_timestamps=0`); create a non-root user; `COPY --from=vendor` the vendor + app code and `COPY --from=assets` the built `public/build`; set `ENTRYPOINT` to `entrypoint.sh`; `USER` non-root. No build toolchain shipped (I-3, I-4, R7).
- [X] T007 Add the four `Dockerfile` final targets extending `runtime`: `fpm` (`EXPOSE 9000`, `CMD php-fpm -F`, HEALTHCHECK fpm ping), `queue` (`CMD php artisan queue:work --queue=default,broadcasts --tries=1 --timeout=1320`), `reverb` (`EXPOSE 8080`, `CMD php artisan reverb:start --host=0.0.0.0 --port=8080`), `init` (`CMD php artisan migrate --force` **only** — caches are NOT warmed here, see F2/T008) (I-1, I-6, D1, D2).
- [X] T008 [P] Create `docker/php/entrypoint.sh`: `*_FILE` → env shim for `APP_KEY_FILE`/`DB_PASSWORD_FILE`/`OPENSTREET_API_KEY_FILE`/`REVERB_APP_SECRET_FILE` (read file, export var, `unset` the `_FILE`); assert required vars non-empty and **fail fast naming the missing one**; **then warm this container's own caches** (`config:cache`, `route:cache`, `event:cache`, `view:cache`, `storage:link` — F2/E-5; the `init` role may skip them) so the serving containers are cached on their own fs; `exec "$@"` as PID 1; never log secret values (contracts/images.md E-1..E-5, D4, D6, FR-015).
- [X] T009 [P] Create `docker/php/healthcheck.sh` taking a role arg: `fpm` → php-fpm ping on `:9000`, `reverb` → TCP connect `:8080`, `queue` → worker-process liveness (R7, FR-013).
- [X] T010 [P] Create `docker/php/php.ini` + `docker/php/opcache.ini` with production settings (opcache enabled, `validate_timestamps=0`, sane memory/limits) referenced by the `runtime` stage (R7).
- [X] T011 [P] Create `docker/nginx/Dockerfile` (`FROM nginx:1.31-trixie-perl`): copy built `public/` (from the `assets` stage/context) to the web root and `default.conf`; `HEALTHCHECK` `GET http://127.0.0.1/up` (contracts/images.md front section, D3).
- [X] T012 [P] Create `docker/nginx/default.conf`: serve static `public/` with SPA/PHP fallback to `index.php`; `location ~ \.php$` → `fastcgi_pass serve:9000`; the Reverb websocket path **`location /app`** → `proxy_pass http://websocket:8080` with `proxy_http_version 1.1` + `Upgrade`/`Connection: upgrade` headers (single same-origin ingress; the Echo client connects same-origin to `/app`, F1/D3, R4).

**Checkpoint**: `docker build --target {fpm|queue|reverb|init} .` and the nginx image build succeed; finals are thin, non-root, secret-free.

---

## Phase 3: User Story 1 - One-command bring-up (Priority: P1) 🎯 MVP

**Goal**: One `docker compose up` starts web + serve + queue + websocket + database, ordered and healthy, app reachable.

**Independent Test**: On a clean host, supply config + secrets, run the single command, confirm every service healthy and the home page loads (quickstart §3–4).

- [X] T013 [US1] Create `docker-compose.yml` with all six services per `contracts/compose-services.md`: `database` (`postgres:18`, `POSTGRES_*`, `pg_isready` healthcheck, `pgdata` volume), `backend` (target `init`, `depends_on database: service_healthy`, `restart: "no"`), `serve`/`queue`/`websocket` (their targets, `depends_on backend: service_completed_successfully`, healthchecks via `healthcheck.sh`), `web` (nginx build, `ports: ${HTTP_PORT}:80`, `depends_on serve: healthy`), one bridge network, `restart: unless-stopped` for long-runners (FR-001, FR-009, C-1..C-3).
- [X] T014 [US1] In `docker-compose.yml`, add the top-level `secrets:` block (file-backed `app_key`/`db_password`/`openstreet_api_key`/`reverb_app_secret`) and reference them per service with `*_FILE` env paths only (no literal secret values); `database` uses `POSTGRES_PASSWORD_FILE` (C-4, CR-2, FR-011).
- [ ] T015 [US1] Verify per quickstart §3–4: `docker compose up -d --build` → `docker compose ps` shows long-runners healthy and `backend` exited 0; `curl /up` returns 200; home page loads (SC-001).

**Checkpoint**: full stack up with one command, healthy, reachable — MVP demoable.

---

## Phase 4: User Story 2 - Data and schema survive restarts (Priority: P2)

**Goal**: Auto-migrate on first start; data persists across recreate; app waits for DB.

**Independent Test**: First start auto-migrates; create data, `down` then `up`, data still present (quickstart §6).

- [ ] T016 [US2] Confirm `backend`(init) runs `migrate --force` gated on DB health (T007/T013) and that `pgdata` is the sole durable volume; verify per quickstart §6: empty DB → auto-migrated; create data → `docker compose down && up` → data retained; DB-not-ready start → app waits, no crash-loop (FR-007/008/009, SC-004, SC-007).

**Checkpoint**: durable, self-initializing database.

---

## Phase 5: User Story 3 - Env config + secret hygiene (Priority: P3)

**Goal**: Everything env-driven; no secrets in images; missing value fails fast; same images redeploy by config only.

**Independent Test**: Inspect images for secrets; deploy two config sets without rebuild; omit a secret and see a named failure (quickstart §7).

- [ ] T017 [US3] Verify secret/portability contract: `docker history`/`docker inspect` of every shipped image reveals **no** secret (SC-005); removing a required secret/var makes the affected service exit with a message naming it (T008, SC-006); redeploy by changing only `.env.production` + `docker/secrets/*` (no rebuild) adopts the new config (SC-002, FR-010/011/012). Fix the entrypoint required-var list if any gap is found.

**Checkpoint**: clean secret handling + environment portability proven.

---

## Phase 6: User Story 4 - Background + realtime across containers (Priority: P3)

**Goal**: A queued optimization is processed by `queue` and broadcast via `websocket`, reaching the browser through the nginx WS proxy.

**Independent Test**: Run an optimization that queues; result appears in the browser with no manual refresh (quickstart §5).

- [ ] T018 [US4] Verify cross-container wiring: the nginx WS proxy (T012) reaches `websocket`, and `queue` processes the `default`/`broadcasts` queues. Run quickstart §5 — start a queued optimization, confirm `queue` logs the job and the result arrives in the browser in real time; confirm the existing poll fallback still settles the result if `websocket` is briefly down (SC-003, spec US4 scenarios 1–2).

**Checkpoint**: the multi-service topology works end to end.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T019 [P] Add a `Deployment` section to `README.md` (build, secrets, `docker compose up`, verify, teardown) linking `specs/008-containerized-deployment/quickstart.md`.
- [X] T020 [P] Add a CI job (e.g. `.github/workflows/images.yml`) that `docker build`s each target (`fpm`/`queue`/`reverb`/`init`) + the nginx image on PRs, as a regression guard that the images stay buildable (pinned bases, lockfile-first).
- [ ] T021 Walk the full `specs/008-containerized-deployment/quickstart.md` end to end on a clean host and confirm every section (healthy bring-up, e2e, persistence, secret hygiene) passes.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: depends on Setup; **blocks all stories** (no compose without images).
- **US1 (Phase 3)**: depends on Foundational. The MVP.
- **US2/US3/US4 (Phases 4–6)**: depend on US1 (a running stack to verify); independent of each other.
- **Polish (Phase 7)**: after the stories.

### Within phases

- Setup: T001, T002, T003 all [P].
- Foundational: the `Dockerfile` stages are one file → **sequential** T004 → T005 → T006 → T007; T008, T009, T010, T011, T012 are separate files → [P] (and parallel to the Dockerfile chain).
- US1: T013 → T014 (same file, sequential) → T015 (verify).
- US2/US3/US4: each a single verification task; can run in parallel once US1 is green.

### Parallel Opportunities

- All of Setup (T001–T003).
- Foundational non-Dockerfile files (T008–T012) alongside the T004–T007 chain.
- US2 (T016), US3 (T017), US4 (T018) in parallel after US1.
- Polish T019, T020 [P].

---

## Implementation Strategy

### MVP First (User Story 1)

1. Setup → Foundational (images + entrypoint + nginx) → US1 (compose stack).
2. **STOP and VALIDATE**: `docker compose up -d --build`, all healthy, `/up` 200, home page loads. Demoable.

### Incremental Delivery

1. US1 → one-command healthy stack (MVP).
2. US2 → durability + auto-migrate.
3. US3 → secret hygiene + portability.
4. US4 → background/realtime e2e.
5. Polish → README + CI image build + full quickstart.

---

## Notes

- [P] = different files, no dependencies.
- **One small app change** (`resources/js/lib/echo.ts` same-origin WS fallback, F1); otherwise deployment files only. `pgsql` already in `config/database.php`. Engine switch is `DB_CONNECTION=pgsql`.
- All image references are **exact pinned tags** (CR-1); build-only `node:22-alpine`/`composer:2.8` never ship.
- Secrets enter only at runtime via file-mounted Docker secrets + the `*_FILE` shim (CR-2); only the public
  `VITE_REVERB_APP_KEY` is a build arg.
- `web` is the single ingress; it proxies PHP → `serve:9000` and the websocket → `websocket:8080` (D3).
