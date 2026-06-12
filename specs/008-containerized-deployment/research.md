# Research: Containerized Deployment

## R1 — Image bases, version pinning, and UBI-clean layering

**Decision**: Pin **every** base by exact tag: `php:8.4.22-alpine`, `nginx:1.31-trixie-perl`, `postgres:19`,
and the build-only `node:22-alpine` + `composer:2.8`. Apply clean-image (UBI-style) hygiene to the custom
images: minimal packages, caches cleaned inside the same `RUN`, a non-root final user, no build toolchain in
the shipped layers, and a single concern per image. Order layers heaviest/most-stable first.

**Rationale**: The brief mandates explicit versions so production never updates without our knowledge — a
floating `latest`/minor tag can silently change PHP, OpenSSL, or libpq under a stable deployment. IBM's UBI
guidance (minimal surface, pinned/curated base, run as non-root, drop build deps from the final, one concern
per container, reproducible) maps directly onto Alpine here even though the base isn't literally a Red Hat UBI:
the *principles* (small, reproducible, least-privilege, no secrets) are what we adopt. Layer order matters for
cache: system deps and compiled PHP extensions change rarely, so they go first; dependency installs are driven
by lockfiles copied **before** the source; the frequently-changing application code is copied **last**, so a
code edit rebuilds only the cheap tail.

**Alternatives considered**:
- *Floating tags (`php:8.4`, `postgres:latest`)*: smaller maintenance but violates the stated stability
  requirement. Rejected.
- *Literal Red Hat UBI base*: heavier and off-brief (the brief names `php:*-alpine`). We take the UBI
  *principles*, not the image. Rejected as a base.

## R2 — One multi-stage Dockerfile with four service targets vs. four separate Dockerfiles

**Decision**: A single multi-stage `Dockerfile`. Build stages `vendor` (Composer) and `assets` (Node) compile
once; a `runtime` stage assembles the lean image (php-alpine + runtime libs + `pdo_pgsql` + vendor + code +
built assets, non-root); four final targets `fpm`, `queue`, `reverb`, `init` extend `runtime` and set only the
entrypoint/CMD. Build each service image with `--target`.

**Rationale**: The four PHP services run the **same** codebase and dependencies — duplicating that across four
Dockerfiles would duplicate the heavy layers and drift over time. Sharing one base build means the expensive
work (extension compile, `composer install`, asset build) happens once and is cache-shared; the per-service
images are a single thin layer (a different command). This is exactly the layer reduction + thin-final outcome
the brief asks for, while still producing "one image per service."

**Alternatives considered**:
- *Four standalone Dockerfiles*: honors "one per service" literally but duplicates ~95% of every build and
  invites drift. Rejected.
- *One image, four `command:` overrides in compose (no per-service image)*: even leaner, but the brief
  explicitly wants an image per service (clearer provenance, independent healthcheck/entrypoint). Followed the
  brief with build targets — best of both.

## R3 — Identity of the fourth ("back-end") PHP service

**Decision**: Make `backend` a **run-once initializer**: `php artisan migrate --force`, then
`config:cache`/`route:cache`/`event:cache`/`view:cache` and `storage:link`, then exit 0. App services depend on
it via `service_completed_successfully`.

**Rationale**: The brief lists four PHP services (websocket, queue, serve, back-end) but the app has **no
scheduled tasks** (`routes/console.php` holds only the stock `inspire` command), so a `schedule:work` daemon
would idle. The genuinely-needed fourth role is initialization: something must migrate the schema and warm the
production caches exactly once, before the long-running services serve traffic. A one-shot init container is the
clean, idempotent way to do that and directly satisfies FR-007 (auto-migrate before serving) and FR-009 (it
itself waits on DB health). Caching at container start (not build) keeps env changes effective without a
rebuild (FR-012).

**Alternatives considered**:
- *Run migrations inside the `serve` entrypoint*: races across multiple app replicas and reruns on every
  restart. Rejected — a single gated one-shot is safer.
- *A `schedule:work` scheduler*: no scheduled tasks exist, so it would do nothing today. Deferred — the slot
  converts to this if/when tasks are added.

## R4 — nginx as single ingress, and how the websocket reaches the browser

**Decision**: The `web` (nginx) image is the only published ingress. It serves the baked `public/` static
assets, forwards `*.php` to `serve:9000` over FastCGI, and reverse-proxies the Reverb websocket path to
`websocket:8080` (with `Upgrade`/`Connection` headers). The browser connects **same-origin**; the client
derives host/scheme from `window.location`, so the front image needs no per-environment host.

**Rationale**: A single ingress means one published port and no CORS/cross-origin websocket setup. Crucially it
keeps the front image **environment-portable** (FR-012): if the websocket host/scheme were baked into the Vite
build, every environment would need its own image. Proxying WS through the same origin removes that coupling —
only the **public** app key is baked (see R5). Pusher-protocol clients (Laravel Echo/Reverb) support a
path-based same-origin connection, so this is a standard configuration.

**Alternatives considered**:
- *Expose Reverb directly on its own host port*: simpler nginx config but forces a baked WS host/scheme per
  environment and a second published port. Rejected for portability.

## R5 — Secret management: keep secrets out of images, inject at run time

**Decision**: Treat `APP_KEY`, `DB_PASSWORD`, `OPENSTREET_API_KEY`, `REVERB_APP_SECRET` as **Docker Compose
secrets** (file-mounted at `/run/secrets/*`, mode `0400`). `postgres` consumes `POSTGRES_PASSWORD_FILE`
natively. The PHP entrypoint implements a `*_FILE` convention: for each `VAR_FILE` it reads the file and
exports `VAR`, then `unset`s `VAR_FILE`, before `exec`-ing the process — so Laravel reads ordinary env while the
secret value never appears in a build arg, image layer, `docker history`, or `docker inspect`. Non-secret
configuration is supplied via a gitignored `env_file` (`.env.production`, `0600`). The only build-time value
baked into the front-end assets is the **public** `VITE_REVERB_APP_KEY` (a client-side key by the Pusher
protocol's design — not a secret). The entrypoint also **fails fast** (R/D6) if any required secret/var is
missing, naming it.

**Rationale**: FR-011 forbids secrets in images; build args and `ENV` are both visible in image metadata, so
neither may carry a secret. File-mounted secrets + a `*_FILE` shim is the established pattern (the official
postgres/mysql images use it) and works without Laravel needing native `_FILE` support. Keeping only the public
Reverb key at build time is safe and is what preserves R4's portability.

**Alternatives considered**:
- *Plain `environment:`/build args for secrets*: simplest but leaks into image/inspect metadata. Rejected
  (FR-011).
- *An external secrets manager (Vault, SSM)*: stronger for fleets but over-scoped for a single-host compose.
  Deferred — the `*_FILE` shim is forward-compatible with it.

## R6 — Logging and statelessness

**Decision**: Set `LOG_CHANNEL=stderr` so each service streams logs to stdout/stderr for `docker logs`. App
containers carry **no** mounted state: sessions, queue, and cache live in Postgres (already their drivers), and
built assets are baked into the image. Only `postgres` gets a named data volume.

**Rationale**: Constitution IV forbids silent failure — routing every service's log to the container stream
makes failures visible to the operator (FR-014) and to any aggregator. Stateless app containers are restart-
and replace-safe (FR-017) and make the only durable thing — the database volume — the single backup concern
(FR-008).

**Alternatives considered**:
- *File logs on a shared volume*: adds a volume, complicates multi-container log access, and risks silent loss
  on container replacement. Rejected.

## R7 — PHP extensions, process management, and healthchecks

**Decision**: Build `pdo_pgsql` in the `vendor`/build stage (needs `$PHPIZE_DEPS` + `postgresql-dev`); the
final keeps only the runtime `libpq` and the compiled extension. Add opcache (production settings) and an
`opcache.preload`-free, `validate_timestamps=0` config (code is immutable in the image). Healthchecks per role:
`postgres` → `pg_isready`; `serve` → php-fpm ping (`cgi-fcgi`/script on `:9000`); `web` → HTTP `GET /up`
(Laravel's built-in health route through fpm); `websocket` → TCP connect on `:8080`; `queue` → liveness via the
worker process / `queue:monitor`. `backend` (one-shot) needs no healthcheck — it's gated by exit status.

**Rationale**: Compiling the extension in a builder and shipping only the `.so` + `libpq` keeps the final thin
(R2). `validate_timestamps=0` is safe and fast because image code never changes at runtime. Per-role
healthchecks give compose the readiness signal FR-013 requires and drive the ordered bring-up (Flow).

**Alternatives considered**:
- *PECL/`install-php-extensions` helper in the final image*: convenient but drags build tooling into the
  shipped layers. Rejected — compile in the builder, copy the artifact.
- *No healthchecks, rely on `depends_on` start order only*: start order ≠ readiness; a started-but-not-ready DB
  still breaks migrations. Rejected (FR-009/FR-013).
