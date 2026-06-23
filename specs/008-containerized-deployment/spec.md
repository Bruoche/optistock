# Feature Specification: Containerized Deployment with a Single Compose Stack

**Feature Branch**: `008-containerized-deployment`

**Created**: 2026-06-11

**Status**: Draft

**Input**: User description: "We need to prepare the application's deployment. For that we will prepare to have images made to deploy the application's back end so the back services, front, and database can be started in containers via a single docker-compose."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Bring the whole stack up with one command (Priority: P1)

An operator deploying the application checks out the repository (or pulls the built images), provides the
required configuration values, and starts everything — the web application, its background services, the
realtime messaging service, and the database — with a single command. After it settles, the application is
reachable in a browser and ready to use.

**Why this priority**: This is the core of the feature and the MVP. Without a one-command bring-up of the full
stack, there is no deployment story. It delivers value on its own: the application can be run anywhere a
container runtime exists, with no manual per-service setup.

**Independent Test**: On a clean host with only the container runtime installed, supply the documented
configuration, run the single start command, and confirm every service reports healthy and the application's
main page loads.

**Acceptance Scenarios**:

1. **Given** a clean host with the container runtime and the documented configuration in place, **When** the
   operator runs the single start command, **Then** the web app, background worker, realtime service, and
   database all start and report healthy, and the application home page is reachable.
2. **Given** the stack is running, **When** the operator stops it and starts it again, **Then** all services
   come back healthy without manual intervention.
3. **Given** a service fails to start, **When** the operator inspects it, **Then** that service's logs clearly
   state why (e.g. a missing required configuration value), rather than failing silently.

---

### User Story 2 - Data and schema survive restarts (Priority: P2)

The operator expects that stopping, restarting, or recreating the containers does not lose application data,
and that a brand-new deployment initializes its database schema automatically on first start.

**Why this priority**: A deployment that loses data on restart is not usable in any real setting. This builds
on US1 and makes the stack durable, but US1 is demonstrable without it.

**Independent Test**: Start the stack, create some data through the application, restart/recreate the
containers, and confirm the data is still present; separately, start against an empty database and confirm the
schema is created automatically.

**Acceptance Scenarios**:

1. **Given** a running stack with data created through the app, **When** the containers are recreated, **Then**
   the previously created data is still present.
2. **Given** a first-time deployment against an empty database, **When** the stack starts, **Then** the
   database schema is initialized automatically before the web app begins serving traffic.
3. **Given** the database service is not yet ready, **When** the web app starts, **Then** the web app waits for
   the database rather than crashing, and proceeds once the database is reachable.

---

### User Story 3 - Configure everything through environment, no secrets in images (Priority: P3)

The operator moves the deployment between environments (e.g. staging and production) by changing only
configuration values — application URL, database credentials, the external route-optimization API key, the
realtime service keys — without editing code or rebuilding for each environment, and with no secret values
baked into the images.

**Why this priority**: Environment-driven configuration and secret hygiene are required for safe, repeatable
deployments, but the stack is already demonstrable (US1/US2) before this is fully realized.

**Independent Test**: Deploy the same images twice with two different configuration sets and confirm each
behaves per its configuration; inspect the built images and confirm no secret values are present in them.

**Acceptance Scenarios**:

1. **Given** the built images, **When** the operator supplies a different configuration set, **Then** the
   application adopts those values (URL, database, external API key, realtime keys) without rebuilding.
2. **Given** a required configuration value is missing, **When** the stack starts, **Then** it fails fast with
   a clear message naming the missing value.
3. **Given** the built images, **When** they are inspected, **Then** no secrets (keys, passwords, tokens) are
   embedded in them.

---

### User Story 4 - Background and realtime features work across the container boundary (Priority: P3)

A user of the deployed application performs a tour optimization end to end: the request is accepted, the
long-running work is processed by the background worker, and the result is delivered back to the browser in
real time — all while every part runs in separate containers.

**Why this priority**: Proves the multi-service wiring (web ↔ worker ↔ realtime ↔ database) actually works in
the containerized topology, not just that each service starts. Lower priority because it validates US1–US3
rather than adding new capability.

**Independent Test**: From the deployed app, start an optimization that requires background processing and
confirm the result arrives in the browser without a manual refresh.

**Acceptance Scenarios**:

1. **Given** the running stack, **When** a user starts an optimization that is queued, **Then** the background
   worker processes it and the result is delivered to the browser in real time.
2. **Given** the realtime service is temporarily unreachable, **When** a result completes, **Then** the user
   still eventually receives it through the application's existing fallback, rather than being stuck.

---

### Edge Cases

- **Database not ready at startup**: the web app and worker wait/retry for the database instead of crash-looping.
- **Missing required configuration** (application key, external API key, database credentials, realtime keys):
  the affected service fails fast with a clear, logged message rather than starting in a broken state.
- **Long-running optimization job**: the worker's timeouts accommodate jobs that can run for minutes (the
  application already assumes this), so a slow external call does not kill an in-flight job prematurely.
- **Container restart mid-job**: a job interrupted by a restart is retried or surfaced as failed (logged), not
  silently dropped.
- **Host port already in use**: the start surfaces the conflict clearly so the operator can remap ports.
- **External route-optimization API unreachable**: the application surfaces the failure to the user rather than
  hanging indefinitely (covered by existing timeouts), and the failure is logged.
- **Stack stopped uncleanly**: a subsequent start recovers without manual repair of state.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The deployment MUST start the entire application stack — web application, background job
  processor, realtime messaging service, and database — from a single orchestration definition with one
  command.
- **FR-002**: The web application service MUST serve both the user-facing interface and the application's API
  over a known, configurable host port.
- **FR-003**: The user-facing interface (front end) MUST be built and served as part of the deployment, with no
  separate manual build step required at run time.
- **FR-004**: A background job processor service MUST run continuously and process the application's queued work,
  including both the standard and the realtime-broadcast work queues.
- **FR-005**: A realtime messaging service MUST run and accept client connections so that asynchronous results
  reach the browser without a manual refresh, exposed on a known, configurable host port.
- **FR-006**: A database service MUST provide persistent relational storage for the application, including its
  application data and its session, queue, and cache stores.
- **FR-007**: The database schema MUST be initialized/migrated automatically on first start, before the web
  application begins serving traffic.
- **FR-008**: Database data MUST persist across container stop, restart, and recreation via durable storage.
- **FR-009**: Services that depend on the database MUST wait for it to be ready before attempting to use it,
  rather than failing when it is briefly unavailable.
- **FR-010**: All environment-specific configuration (application URL, database credentials, external
  route-optimization API key and endpoints, realtime service keys, application secret key) MUST be supplied via
  environment configuration at deploy time, not hard-coded.
- **FR-011**: Built images MUST NOT contain secret values; secrets MUST be injected at run time.
- **FR-012**: The same built images MUST be reusable across environments by changing only configuration, with no
  rebuild required per environment.
- **FR-013**: Each service MUST expose a health/readiness signal so the orchestration can determine when the
  stack is ready, and an unhealthy service MUST be distinguishable from a healthy one.
- **FR-014**: Every service MUST emit its logs to a location the operator can inspect; no service may fail
  silently — startup and runtime failures MUST be logged with enough context to diagnose them.
- **FR-015**: A required-but-missing configuration value MUST cause the affected service to fail fast with a
  clear message identifying the missing value.
- **FR-016**: The images MUST be reproducibly buildable from the repository (definition checked into version
  control), so a deployer can produce them without bespoke manual steps.
- **FR-017**: The stack MUST be cleanly stoppable and restartable, returning to a healthy running state without
  manual state repair.

### Key Entities *(include if data involved)*

- **Service**: A unit of the running application (web application, background job processor, realtime messaging
  service, database). Each has a role, a health state, configuration inputs, and logs.
- **Persistent volume**: Durable storage holding database state that outlives any individual container.
- **Configuration set**: The collection of environment-specific values (URL, credentials, keys, secrets) that
  parameterize one deployment of the images.
- **Orchestration definition**: The single declarative description that ties the services, their dependencies,
  ports, volumes, and configuration together and is started with one command.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: From a clean host with only the container runtime and the documented configuration, a single
  command brings the full stack to a healthy, reachable state with no additional manual steps.
- **SC-002**: A deployer can move the deployment to a new environment by editing only one configuration set
  (zero code or image changes).
- **SC-003**: A user can complete a full tour optimization (interface + API + background processing + realtime
  delivery) end to end with every component running in separate containers.
- **SC-004**: 100% of application data created before a full stack restart/recreation is still present
  afterward.
- **SC-005**: Inspection of the built images reveals zero embedded secret values.
- **SC-006**: When a required configuration value is absent, the stack reports the specific missing value
  instead of starting in a broken or silent-failure state.
- **SC-007**: A first-time start against an empty database yields a fully migrated, ready application without
  any manual database step.

## Assumptions

- **Single-host orchestration**: Deployment targets a single host running a container runtime with a compose
  capability; cluster orchestration (e.g. multi-node schedulers) is out of scope for this feature.
- **Relational database engine**: The database container provides a server-based relational engine (replacing
  the development file-based database). The application's session, queue, and cache stores — already configured
  to use the database — share this engine. MySQL is the assumed default; the choice is configuration-level and
  can be changed without altering the feature's shape.
- **Front end packaged with the application image**: The interface is compiled at image build time and served by
  the web application (the app is a server-rendered SPA), so there is no standalone front-end runtime container;
  realtime client settings needed at build time are provided then.
- **No separate cache/queue broker**: Queue and cache use the database, so no additional in-memory broker
  service is required.
- **External route-optimization API**: The deployed containers have outbound network access to the external
  route-optimization service; that service itself is not part of this deployment.
- **TLS / public ingress handled externally**: The stack exposes plain application and realtime ports; HTTPS
  termination, domains, and public ingress are handled by an external reverse proxy and are out of scope here.
- **Secrets provided at deploy time**: Configuration (including secrets) is supplied to the running stack via an
  environment file or equivalent injected at deploy time and is never committed to version control or baked into
  images.
- **Mail**: Outgoing mail uses a no-op/log path; no mail service is included in this stack.
- **Scope of this feature is bring-up and run**: Continuous-delivery pipelines, image-registry publishing,
  automated backups, autoscaling, and zero-downtime rollout are out of scope; the deliverable is the buildable
  images plus the single compose definition that runs them.
