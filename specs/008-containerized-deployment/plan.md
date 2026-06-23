# Implementation Plan: Containerized Deployment with a Single Compose Stack

**Branch**: `008-containerized-deployment` | **Date**: 2026-06-11 | **Spec**: [spec.md](spec.md)

## Summary

Package the application as a set of pinned container images and start the whole stack — front end, the four
back-end PHP services, and the database — with one `docker compose up`. The PHP code is built once in a
multi-stage build (Composer vendor stage + Node asset stage) and reused by four thin, single-concern final
images that differ only by entrypoint: **serve** (php-fpm), **queue** (worker), **websocket** (Reverb), and
**back-end** (a one-shot migrate/optimize init that runs before the others). A pinned `nginx:1.31-trixie-perl`
image serves the built static assets and reverse-proxies PHP (FastCGI → fpm) and the websocket (→ reverb),
acting as the single ingress. `postgres:18` provides durable storage; sessions, queue, and cache already use
the database driver, so no extra broker is needed. Secrets are injected at run time (Docker Compose secrets +
a `*_FILE` entrypoint shim), never baked into images; logs go to stderr; healthchecks + `depends_on` give an
ordered, self-reporting bring-up.

## Technical Context

**Runtime topology** (from the spec + the app's actual needs):

| Service | Image | Role | Long-running? |
| --- | --- | --- | --- |
| `web` (front) | custom on `nginx:1.31-trixie-perl` | ingress: static assets + FastCGI proxy to `serve` + WS proxy to `websocket` | yes |
| `serve` | custom on `php:8.4.22-alpine` (target `fpm`) | php-fpm, handles HTTP PHP (Inertia + API) | yes |
| `queue` | same base (target `queue`) | `php artisan queue:work` — `default` + `broadcasts` queues | yes |
| `websocket` | same base (target `reverb`) | `php artisan reverb:start` — broadcasts to browsers | yes |
| `backend` (init) | same base (target `init`) | one-shot: `migrate --force` only, then exits 0 (caches warmed per-service at startup — F2) | no (run-once) |
| `database` | `postgres:18` (unmodified) | durable relational store; also session/queue/cache backend | yes |

**Stack**: Laravel 13 (PHP 8.4) + React/Inertia/Vite front end + Reverb (websockets) + PostgreSQL.

**Image bases (all pinned, per the brief)**: `php:8.4.22-alpine`, `nginx:1.31-trixie-perl`, `postgres:18`,
plus a pinned `node:22-alpine` and `composer:2.8` used **only** in build stages (not shipped). Pinning by exact
tag (not `latest`/floating) is mandatory so production never moves under us.

**Storage**: one named volume for `postgres` data. App containers are stateless (logs → stderr; cache/session
→ database; built assets baked into the image), so they need no volumes — clean 12-factor.

**Testing**: Dockerfiles aren't unit-testable; verification is the `quickstart.md` walkthrough plus the
compose healthchecks (every service must report healthy and the end-to-end optimization must complete). A CI
build of the images is the regression guard.

**`pgsql` readiness**: `config/database.php` already defines the `pgsql` connection (port 5432). Switching from
the dev sqlite to Postgres is **configuration only** — no code change — provided the PHP image carries the
`pdo_pgsql` extension.

**Current state (touch points — all new files, plus one small front-end change: `resources/js/lib/echo.ts`
same-origin WS fallback, F1)**:
- `Dockerfile` (multi-stage, build targets `fpm` / `queue` / `reverb` / `init`).
- `docker/nginx/Dockerfile` (+ `default.conf`) — the front image.
- `docker/php/entrypoint.sh`, `docker/php/*.ini`, `docker/php/healthcheck.sh`.
- `docker-compose.yml` (the single stack), `.env.production.example`, `docker/secrets/*.example`.
- `.dockerignore`.
- Config-only env: `DB_CONNECTION=pgsql`, `LOG_CHANNEL=stderr`, `APP_ENV=production`, `APP_DEBUG=false`, etc.

**Project Type**: web app being containerized for single-host deployment.

**Performance/Scale**: single-host compose; layer caching + multi-stage thin finals keep builds and image
size down; opcache + `config:cache` for runtime speed. Out of scope: clustering, autoscaling, zero-downtime.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — no app behavior changes; verification is the quickstart end-to-end run + per-
  service healthchecks + a CI image build. Image builds are reproducible from the repo (pinned bases, lockfiles
  copied before code). PASS.
- **II. Readable & Simple** — one multi-stage Dockerfile with named targets over four near-duplicate files;
  compose service names map 1:1 to roles; comments reserved for the non-obvious (UBI/layer-order rationale,
  the `*_FILE` shim). PASS.
- **III. Simple & Transparent** — shared base build, four single-concern finals, no Redis (DB-backed
  queue/cache/session), nginx as the only ingress. Each container does one thing. PASS.
- **IV. Robustness — no silent failure** — entrypoint **fails fast** if a required secret/var is missing
  (names it); `depends_on` + healthchecks gate startup (DB healthy → init migrates → app services start →
  nginx); logs stream to stderr so Docker captures every service's failures; the init one-shot's non-zero exit
  aborts the bring-up visibly. PASS.
- **V. Performance with Clarity** — heaviest/most-stable layers first (system deps + PHP extensions), then
  lockfile-driven dependency install, then app code; builder stages drop all compilers from the final image;
  opcache + cached config/routes. Measurable: thin finals, warm cache. PASS.
- **VI. Consistent, Reusable Styling** — no front-end styling change (assets built as-is). N/A → PASS.

No violations.

## Decisions

- **D1 — One multi-stage Dockerfile, build stages + four targets, shared base.** Stages: `vendor` (Composer,
  `install --no-scripts` — no DB build tools needed) → `ext` (**`FROM php:8.4.22-alpine`**, builds `pdo_pgsql`
  with `$PHPIZE_DEPS` + `postgresql-dev`) → `assets` (Node, `npm ci` + `npm run build`) → `runtime`
  (php:8.4.22-alpine + runtime `libpq` + the `pdo_pgsql.so` **copied from `ext`** + vendor + app code + built
  `public/build`, non-root). Four thin targets (`fpm`, `queue`, `reverb`, `init`) extend `runtime` and set only
  the entrypoint/CMD. Building the extension on the **same** PHP base as runtime avoids the ABI mismatch that
  compiling it in the `composer` image would risk (F3). "One image per service" is honored (four tagged images)
  while the heavy layers are built once and shared — the layer-reduction the brief asks for. (research R1, R2.)

- **D2 — "back-end" service = a run-once migrate init.** The app has no scheduled tasks
  (`routes/console.php` is the stock `inspire` only), so the fourth PHP service is the initializer:
  `migrate --force`, then exit 0. It does **not** warm caches: `config/route/event/view:cache` and `storage:link`
  write to the init container's **own** filesystem, which is thrown away on exit, so the separate
  `serve`/`queue`/`websocket` containers would never see them (F2). Instead each serving container warms its own
  caches in the shared entrypoint at startup (D5/E-5) — effective and still env-driven (FR-012). Compose gates
  the app services on the init's **successful completion** (`service_completed_successfully`), satisfying FR-007
  (auto-migrate before serving) and FR-009 (wait for DB). If scheduled tasks are added later this slot becomes a
  `schedule:work` long-runner. (research R3.)

- **D3 — nginx is the single ingress; it proxies both PHP and the websocket.** Static assets served directly
  from the baked `public/`; `*.php` → FastCGI `serve:9000`; the Reverb websocket path `/app` → `websocket:8080`
  (with `Upgrade`/`Connection` + `proxy_http_version 1.1`). One published host port (HTTP/WS). The browser
  connects **same-origin**: `resources/js/lib/echo.ts` derives `wsHost`/`wsPort`/scheme from `window.location`
  when the `VITE_REVERB_*` build vars are absent (they are **not** passed to the container build — only the
  public `VITE_REVERB_APP_KEY` is), and still honors explicit `VITE_REVERB_*` for local dev. So the front image
  carries **no** per-environment host/scheme, keeping it portable (FR-012). This is the one small front-end
  change this feature makes (F1); everything else is new deployment files. (research R4.)

- **D4 — Secrets never in images; injected at run time via Compose secrets + a `*_FILE` shim.** Sensitive
  values — `APP_KEY`, `DB_PASSWORD`, `OPENSTREET_API_KEY`, `REVERB_APP_SECRET` — are Docker **secrets**
  (file-mounted, `0400`). `postgres` reads `POSTGRES_PASSWORD_FILE` natively. The PHP entrypoint reads any
  `*_FILE` var and exports the plain var before `exec`-ing PHP, so Laravel sees normal env without the secret
  ever touching an image layer, build arg, or `docker history`. Non-secret config comes from an `env_file`
  (`.env.production`, gitignored, `0600`). Only the **public** `VITE_REVERB_APP_KEY` (a client key by design)
  is a build arg baked into assets. (research R5 — FR-010, FR-011.)

- **D5 — Logs to stderr, app containers stateless.** `LOG_CHANNEL=stderr` so every service's output is captured
  by `docker logs` (FR-014, Constitution IV). With sessions/queue/cache in Postgres and assets baked in, the
  app containers hold no state → no app volumes, only the `postgres` data volume (FR-008). (research R6.)

- **D6 — Fail-fast config validation in the entrypoint.** Before starting PHP, the entrypoint asserts the
  required vars/secret files are present and non-empty; a missing one aborts with a message naming it
  (FR-015), rather than booting a half-configured app. (research R5.)

- **D7 — Layer order = heaviest & most-stable first.** In every stage: base → system packages / PHP extensions
  → copy **only** `composer.json`+`composer.lock` (resp. `package.json`+`package-lock.json`) → install deps →
  *then* copy application source. Source changes (frequent) don't bust the dependency layers (expensive). UBI-
  clean hygiene throughout: minimal packages, clean caches in the same `RUN`, non-root final, no build tools
  shipped. (research R1, R7.)

## Project Structure (feature-specific)

New files (plus one small front-end edit — `resources/js/lib/echo.ts` same-origin WS fallback, F1):

```
Dockerfile                         # multi-stage; stages: vendor, ext, assets, runtime; targets: fpm, queue, reverb, init
.dockerignore
docker-compose.yml                 # the single stack: web, serve, queue, websocket, backend(init), database
.env.production.example            # non-secret config template (gitignored real file)
docker/
  php/
    entrypoint.sh                  # *_FILE -> env, required-var assertion, then exec
    healthcheck.sh                 # php-fpm ping / reverb TCP / queue liveness
    opcache.ini, php.ini           # production php + opcache tuning
  nginx/
    Dockerfile                     # FROM nginx:1.31-trixie-perl; copy built public/ + conf
    default.conf                   # static + FastCGI(serve:9000) + WS(websocket:8080)
  secrets/
    *.example                      # app_key, db_password, openstreet_api_key, reverb_app_secret (examples)
```

Config-only env (no code): `DB_CONNECTION=pgsql`, `DB_HOST=database`, `LOG_CHANNEL=stderr`,
`APP_ENV=production`, `APP_DEBUG=false`; broadcast/queue/session/cache already DB+reverb.

Out of scope: image-registry publishing, CI/CD pipeline, TLS/ingress (external reverse proxy), automated
backups, multi-node orchestration, zero-downtime rollout.

## Flow (bring-up order)

1. `database` (postgres:18) starts → healthcheck `pg_isready` → **healthy**.
2. `backend` (init) `depends_on database: healthy` → entrypoint loads secrets, asserts required vars →
   `migrate --force` → **exits 0**. (Caches are **not** warmed here — that fs is discarded; see F2/D2.)
3. `serve` / `queue` / `websocket` `depends_on backend: completed_successfully` → each loads secrets, asserts
   vars, **warms its own caches** (`config/route/event/view:cache`, `storage:link`), starts its process →
   healthchecks go **healthy**.
4. `web` (nginx) `depends_on serve: healthy` (and websocket) → serves assets, proxies PHP + WS → `/up` healthy.
5. A user hits the published port → Inertia app loads; an optimization queues → `queue` processes it →
   `websocket` broadcasts → browser updates (FR + SC-003).

## Build/Run contract

See [contracts/images.md](contracts/images.md) (build stages, targets, args, exposed ports, healthchecks) and
[contracts/compose-services.md](contracts/compose-services.md) (service names, ports, env, secrets, volumes,
`depends_on` conditions). The deployment topology and the full env/secret inventory are in
[data-model.md](data-model.md).

## Design Artifacts (this run)

- `research.md` — base-image pinning, multi-stage/UBI-clean layering, `pdo_pgsql`, the init-service
  interpretation, nginx single-ingress + WS proxy, secret-injection strategy, stderr logging, healthchecks.
- `data-model.md` — deployment topology model: services, images, volumes, networks, and the env/secret matrix.
- `contracts/images.md` — the image build contract (stages, targets, args, ports, non-root, healthcheck).
- `contracts/compose-services.md` — the compose service contract (deps, ports, secrets, volumes, healthchecks).
- `quickstart.md` — build, configure secrets, `docker compose up`, verify healthy + end-to-end.

---

Generated by speckit.plan on 2026-06-11
