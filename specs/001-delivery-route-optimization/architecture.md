# Architecture — Delivery Route Optimization

# C4 Model

Describes the feature at **C4 Level 2 (Containers)** and **Level 3 (Components)**.
Derived from the implemented code (branch `001-delivery-route-optimization`),
verified 2026-06-05. Pairs with `README.md` (ops) and `plan.md` (rationale).

Core behaviour: the user submits coordinates → receives an instant `202` +
`job_uuid` (never blocked on the slow external call) → a background worker calls
the OpenStreet TSP API → the result is cached and pushed back over a WebSocket
(with an HTTP polling fallback). Cache hits short-circuit to an instant `200`.

---

## Level 1 recap (context, for orientation)

- **Person — Delivery planner**: authenticated user who needs an optimized stop order.
- **Software system — Optistock**: the application under design.
- **External system — OpenStreet TSP API**: third-party travelling-salesman solver
  (`https://maps.open-street.com/api/tsp/`). Slow (seconds–minutes for large sets),
  unreliable; called only from background work.

---

## Level 2 — Containers

Optistock is **one Laravel codebase**. At runtime it spans a frontend, the
back-end app, a queue worker, and a WebSocket server, over one database —
talking to one external optimizer.

| Container | Tech | Role |
|---|---|---|
| **Frontend** | Inertia + React | Submit stops, render route, receive live updates |
| **Web App** | Laravel (HTTP) | Validate, check cache, enqueue work, respond — never calls the slow API |
| **Queue Worker** | Laravel queue | Optimize off the request: call the API, cache, broadcast |
| **WebSocket Server** | Laravel Reverb | Push the finished route to the browser |
| **Database** | SQLite / MySQL | Cache, job queue, sessions |

```mermaid
C4Container
    title Containers — Optistock (Monolith codebase)

    Person(user, "Delivery planner", "Submits stops, gets optimized route")


    System_Boundary(optistock, "Optistock") {
        System_Boundary(backend, "Back-End") {
            Container(web, "Web App", "Laravel (HTTP)", "Validate, check cache, enqueue, respond")
            Container(worker, "Queue Worker", "Laravel queue", "Optimize off the request: call API, cache, broadcast")
            Container(reverb, "WebSocket Server", "Laravel Reverb", "Push the finished route to the browser")
        }
        ContainerDb(db, "Database", "SQLite / MySQL", "Cache, queue, sessions")
        Container(spa, "Frontend", "Inertia + React", "Submit stops, render route, live updates")
    }

    System_Ext(osm, "OpenStreet TSP API", "External route optimizer (slow)")

    Rel(user, spa, "Uses")
    Rel(spa, web, "Submit / poll", "HTTPS")
    Rel(spa, reverb, "Subscribe / receive", "WebSocket")
    Rel(web, db, "Cache + enqueue", "SQL")
    Rel(worker, db, "Read job, cache result", "SQL")
    Rel(worker, osm, "Optimize request", "HTTPS")
    Rel(worker, reverb, "Push result", "HTTP")
    Rel(reverb, spa, "Deliver result", "WebSocket")
```

---

## Deployment

The four back-end roles are **one codebase / one image**, started as four
processes — the worker splits into a dedicated `broadcasts` process so
notifications never wait behind a long optimization job. The boundary box marks
the shared monolith: same build artifact, different start command.

```mermaid
C4Deployment
    title Deployment — processes from one codebase

    Deployment_Node(client, "User device", "Browser") {
        Container(spa, "Frontend", "Inertia + React", "Runs client-side")
    }

    Deployment_Node(host, "Application host", "Docker / Forge") {
        Deployment_Node(image, "Optistock monolith", "one codebase -> 4 processes") {
            Container(web, "Web App", "php-fpm", "HTTP requests")
            Deployment_Node(qw, "Queue Worker", "Laravel queue") {
                Container(worker, "Optimization Worker", "queue:work", "Optimization jobs")
                Container(bworker, "Broadcast Worker", "queue:work --queue=broadcasts", "Event delivery")
            }
            Container(reverb, "WebSocket Server", "Laravel Reverb (reverb:start)", "Relays results")
        }
    }

    Deployment_Node(dbnode, "Database server", "SQLite / MySQL") {
        ContainerDb(db, "Database", "", "Cache, queue, sessions")
    }
    Deployment_Node(net, "Internet", "") {
        System_Ext(osm, "OpenStreet TSP API", "External optimizer")
    }

    Rel(spa, web, "Submit / poll", "HTTPS")
    Rel(spa, reverb, "Subscribe / receive", "WebSocket")
    Rel(web, db, "Cache + enqueue", "SQL")
    Rel(worker, db, "Read job, cache result", "SQL")
    Rel(worker, osm, "Optimize request", "HTTPS")
    Rel(worker, reverb, "Push result", "HTTP")
```

---

## Level 3 — Optimization flow (back-end)

The business classes on the optimized-call path, in one codebase but two
runtime roles: the request side (Web App) and the async side (Queue Worker).
Boilerplate (auth, validation, rate limiting, typed exceptions) is omitted;
external systems and the frontend appear as plain blocks marking where the
flux enters and exits.

| Class | Role |
|---|---|
| **RouteOptimizationController** | Entry: cache-check → enqueue → respond |
| **RouteNormalizer** | Canonical coordinates + hash → order-independent cache key |
| **RouteCache** | Cache hit/miss, and dedup of identical in-flight requests |
| **OptimizeRouteJob** | Runs the optimization off the request cycle |
| **OpenStreetTspClient** | Calls the external API, maps the optimized tour |
| **Broadcast Events** | Push the result back to the browser |

```mermaid
C4Component
    title Optimization flow — back-end business components

    Container(spa, "Frontend", "SPA", "Enter: submit / Exit: render route")
    ContainerDb(db, "Database", "Cache + queue", "")
    Container(reverb, "WebSocket Server", "Laravel Reverb", "")
    System_Ext(osm, "OpenStreet TSP API", "External optimizer")

    Container_Boundary(web, "Web App — request side") {
        Component(ctrl, "RouteOptimizationController", "Controller", "Cache-check → enqueue → respond")
        Component(norm, "RouteNormalizer", "Service", "Canonical coords + hash → cache key")
        Component(cache, "RouteCache", "Service", "Cache hit/miss + request dedup")
    }

    Container_Boundary(worker, "Queue Worker — async side") {
        Component(job, "OptimizeRouteJob", "Job", "Optimize off the request")
        Component(client, "OpenStreetTspClient", "Service", "Call API, map optimized tour")
        Component(evt, "Broadcast Events", "Events", "Push the result")
    }

    Rel(spa, ctrl, "Submit coordinates")
    Rel(ctrl, norm, "Normalize → key")
    Rel(ctrl, cache, "Hit → respond / Miss → enqueue")
    Rel(ctrl, job, "Dispatch")
    Rel(cache, db, "Get / put", "SQL")
    Rel(job, client, "Optimize")
    Rel(client, osm, "Get tour", "HTTPS")
    Rel(job, cache, "Store result")
    Rel(job, evt, "Dispatch result")
    Rel(evt, reverb, "Push")
    Rel(reverb, spa, "Deliver route")
```

# Sequence Diagram
---

## Runtime sequence — asynchronous optimization flow

The C4 diagrams above are **structural** (who talks to whom). This section is
**temporal**: it shows *when* each call happens and why the user gets an instant
response while the slow external call runs in the background, with the result
delivered over WebSocket the moment the API answers.

Two threads run independently after the `202`:

- **Request thread (C2)** — returns in milliseconds, never touches the external API.
- **Worker thread (C3)** — owns the seconds-to-minutes external call, then pushes
  the result back through the WebSocket Server (Laravel Reverb, C4) over the
  already-open WebSocket.

```mermaid
sequenceDiagram
    autonumber
    actor U as Delivery planner
    participant F as Frontend (SPA)
    participant W as Web App (C2)
    participant DB as Database (cache/queue)
    participant Q as Queue Worker (C3)
    participant API as OpenStreet TSP API
    participant WS as WebSocket Server (C4)

    Note over F,WS: WebSocket already open from page load

    U->>F: Submit coordinates
    F->>W: POST /api/route/optimize
    W->>DB: look up cached route (by hash)

    alt Cache hit
        DB-->>W: cached route
        W-->>F: 200 {done, data}
        F-->>U: Render route immediately
    else Cache miss
        DB-->>W: not found
        W->>DB: enqueue OptimizeRouteJob
        W-->>F: 202 {pending, job_uuid}
        F-->>U: Show "optimizing…" (UI never blocks)

        Note over Q,API: Background — request already returned
        Q->>API: GET /api/tsp (slow call)
        API-->>Q: optimized tour
        Q->>DB: cache result
        Q->>WS: push RouteOptimized
        WS-->>F: RouteOptimized {job_uuid, data}
        F-->>U: Render route the instant API answered
    end
```

The full diagram also covers concurrent-request dedup (atomic in-flight
lock), API-failure broadcast, and the WebSocket-miss polling fallback —
see *Key architectural decisions* below. They are omitted here to keep the
core async flow legible.

**Why this gives high responsiveness:**

- The `202` returns on cache miss **before** any external call — perceived latency
  is one fast DB round-trip, not the multi-minute TSP solve.
- The WebSocket is **already open** when the result lands, so push latency ≈ network
  hop; no client polling delay on the happy path.
- Broadcasts ride a **dedicated `broadcasts` queue** — notification delivery never
  waits behind another long optimization job.
- Polling (`GET result`) is a **fallback only**, for a dropped WS connection.

---

## Key architectural decisions (cross-cutting)

- **Three decoupling boundaries keep the back-end highly available**:
  (1) controller returns `202` + enqueues — frees the frontend; (2) the slow
  external call runs only in the worker — off the HTTP thread; (3) broadcasts are
  queued — a Reverb outage can't cascade into the optimization job.
- **Hard invariant**: `QUEUE_CONNECTION` must be async (`database`), never `sync`,
  or `dispatch()` would run the job inline and block the request for the full
  read-timeout window.
- **Timeout ordering** (see `README.md` §4): `read(600) < job(1260) < worker(1290) < retry_after(1320)`.
  Job ceiling covers all upstream attempts: `(retries+1) × read + backoff = 2×600 + 60 = 1260`
  (`services.openstreet.job_timeout`); `DB_QUEUE_RETRY_AFTER=1320` must exceed the worker timeout
  so a still-running job is never re-reserved and run twice.
- **Order-independent caching**: the normalized sha256 hash means the same stop
  set in any order reuses a cached route.
- **Concurrent-request dedup**: an atomic in-flight lock (`claimInflight`, keyed by
  `{userId}:{hash}`) ensures two simultaneous identical requests share one upstream
  call — the loser reuses the winner's `job_uuid`. The job clears the lock on every
  exit (success / handled failure / crash); the lock's TTL (1380s) self-heals a dead
  worker that never clears it.
- **Auth model**: same-origin session cookies + CSRF (no API tokens); WebSocket
  channel access gated by `channels.php`.
