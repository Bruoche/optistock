# Delivery Route Optimization — Operations Guide

Backend feature: front sends coordinates → gets an instant `202` + `job_uuid` →
opens a WebSocket → is notified when the background job finishes calling the
OpenStreet TSP API. Cache hits short-circuit to an instant `200`.

This document covers everything needed to **run, configure, and verify** the
feature. For the design rationale see `plan.md`; for task tracking see
`tasks.md`.

---

## 1. Environment setup

### 1.1 Required `.env` keys

```dotenv
# OpenStreet TSP route-optimization API
OPENSTREET_API_URL=https://maps.open-street.com/api/tsp/
OPENSTREET_API_KEY=<your-key>
OPENSTREET_API_TIMEOUT=600          # read timeout (s) — API can take minutes
OPENSTREET_API_CONNECT_TIMEOUT=15   # connect timeout (s) — fail fast on dead host
OPENSTREET_API_RETRIES=1            # retries on failure (exponential backoff)
OPENSTREET_API_JOB_TIMEOUT=1260      # single queue-job ceiling (s)

# Async infrastructure (database driver — no Redis)
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=1320            # MUST exceed worker --timeout (see §4)

# WebSocket (Laravel Reverb)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

### 1.2 PHP CA bundle (one-time, per machine) — REQUIRED

The client calls the API over HTTPS. A PHP install with no configured CA
bundle (Herd-lite, bare Windows PHP, some CI images) cannot verify TLS certs
and **every** outbound HTTPS call fails with:

```
cURL error 60: SSL peer certificate or SSH remote key was not OK
```

This is an **environment** problem, not the API. Fix per machine:

1. Download Mozilla's CA bundle from <https://curl.se/ca/cacert.pem>.
2. Save it somewhere stable, e.g. next to the PHP binary
   (`<php-dir>/cacert.pem`).
3. Point `php.ini` at it (find the active ini via `php -i | findstr "Loaded Configuration"`):

   ```ini
   curl.cainfo = "C:\path\to\cacert.pem"
   openssl.cafile = "C:\path\to\cacert.pem"
   ```

4. Verify: `php -i | findstr "curl.cainfo openssl.cafile"` shows the path.

CI and production hosts need the same — most distro PHP packages ship a CA
bundle already; confirm before assuming.

---

## 2. Running the feature locally

Four processes. `composer dev` starts serve + queue + vite together, **but its
queue worker uses the default 60s timeout** which kills long API calls — start
the worker manually with the right timeout instead (see §4).

```powershell
php artisan serve                       # HTTP app  :8000
php artisan reverb:start                # WebSocket  :8080
php artisan queue:work --timeout=1290    # background jobs (see §4 for the number)
npm run dev                             # Vite (frontend, when built)
```

---

## 3. OpenStreet TSP API contract (VERIFIED 2026-06-03)

### Request (GET)

```
{OPENSTREET_API_URL}?pts=lat,lng|lat,lng|...&nb=N&mode=driving&unit=m&tour=closed&key=...
```

- `pts` — pipe-separated `lat,lng` pairs.
- `nb` — point count (must equal number of pairs).
- `mode=driving`, `unit=m` (metres), `tour=closed` (returns to start).

### Response (HTTP 200) — actual shape

```json
{
  "DIMENSION": 4,
  "TOUR": "closed",
  "COMPUTE_TIME": 0.011,
  "TOTAL_TIME": 0.145,
  "OPTIMIZATION": [0, 1, 2, 3],
  "STEPS_DURATIONS": { "TOTAL": 49261, "0": 17910, "1": 17100, "2": 8825, "3": 5426 },
  "STEPS_DISTANCES": { "TOTAL": 1143908, "0": 421122, "1": 406284, "2": 201457, "3": 115045 }
}
```

| Field | Meaning |
|---|---|
| `OPTIMIZATION` | Input-coordinate **indices** in optimal visit order. **No coordinates returned** — the client maps each index back to the coordinate the caller sent. |
| `STEPS_DISTANCES.TOTAL` | Total distance, metres (per-step values sum to it). |
| `STEPS_DURATIONS.TOTAL` | Total duration, seconds (per-step values sum to it). |
| `DIMENSION`, `TOUR`, `COMPUTE_TIME`, `TOTAL_TIME` | Echo / diagnostics, unused. |

> ⚠️ The original spec guessed a `{status:"ok", route:[{lat,lng,order}], distance, time}`
> shape. That was **wrong**. `OpenStreetTspClient::mapToTour()` implements the
> verified shape above.

**Success detection**: presence of an `OPTIMIZATION` array (there is no
`status` field). Error mapping in the client:

| Condition | `TourOptimizationException` code |
|---|---|
| HTTP non-2xx | `api_error` |
| Connection failure / timeout | `timeout` |
| 200 but no `OPTIMIZATION` array | `invalid_response` |

### Internal result shape (what we cache / broadcast)

```json
{
  "ordered_stops": [{ "lat": 49.8998757, "lng": 2.300284, "order": 0 }, ...],
  "total_distance_m": 1143908,
  "total_duration_s": 49261
}
```

---

## 4. Timeout layering — why the worker flag matters

The API can take **minutes** for large point sets; the whole async/WebSocket
design exists to tolerate that. Four independent limits can each kill a long
call, so they must stay ordered:

```
actual_work  ≤  job $timeout (1260)  ≤  worker --timeout (1290)  <  retry_after (1320)
```

| Limit | Where | Value | Notes |
|---|---|---|---|
| HTTP connect | `OpenStreetTspClient` | 15s | fail fast on unreachable host |
| HTTP read | `OpenStreetTspClient` | 600s | tolerate slow compute |
| job `$timeout` | `OptimizeTourJob` | 1260s | from `services.openstreet.job_timeout`; covers all attempts: `(retries+1)×600 + 60` |
| worker `--timeout` | `queue:work` flag | 1290s | **default is 60s — must override** |
| `retry_after` | `config/queue.php` (database) | 1320s | from `DB_QUEUE_RETRY_AFTER`; must exceed worker timeout, else the job is re-dispatched mid-flight → duplicate API calls |

**Not literal "no timeout"**: removing limits lets a hung TCP connection block
a worker forever with no failure broadcast — the frontend would spin
indefinitely. The 600s read ceiling is the safety net: legitimate slow calls
finish; truly-stuck ones fail cleanly → `TourOptimizationFailed` broadcast.
To allow longer, raise `OPENSTREET_API_TIMEOUT`, `OPENSTREET_API_JOB_TIMEOUT`,
`DB_QUEUE_RETRY_AFTER`, and the worker `--timeout` together, keeping the order.

---

## 5. HTTP API

All routes are under `/api`, `web` + `auth` middleware (session-cookie auth,
same-origin Inertia — no API tokens).

### `POST /api/tour/optimize`  (`api.tour.optimize`)

Throttled `tour-optimize` (10/min/user).

```json
{ "coordinates": [[49.8998757, 2.300284], [48.4510104, 6.7483327]] }
```

- 2–10 pairs; `lat` ∈ [-90, 90], `lng` ∈ [-180, 180].
- **200** (cache hit): `{ "status": "done", "data": { ...result } }`
- **202** (cache miss): `{ "status": "pending", "job_uuid": "..." }` — job queued.
- **401** unauthenticated · **422** validation · **429** rate limited.

### `GET /api/tour/status/{job_uuid}`  (`api.tour.status`)

Polling fallback for the WebSocket.

- `{ "status": "pending" }`
- `{ "status": "done", "data": { ...result } }`
- `{ "status": "failed", "error": { "code": "...", "message": "..." } }`
- **404** unknown / expired job.

### WebSocket events (private channel `App.Models.User.{id}`)

- `TourOptimized` → `{ "job_uuid": "...", "data": { ...result } }`
- `TourOptimizationFailed` → `{ "job_uuid": "...", "error": { "code": "...", "message": "..." } }`

Error codes: `api_error`, `timeout`, `invalid_response`, `job_failed`.

### Cache keys (database store)

- Result: `tour:{userId}:{hash}` — 24h. `hash` = sha256 of normalized,
  order-independent coordinates (round 5 decimals, stable-sorted).
- Status: `tour:status:{jobUuid}` — 1h (`pending`/`done`/`failed`).

---

## 6. Verifying it works

### 6.1 Tests

```powershell
php artisan test --filter "TourOptimization|OpenStreetTspClient|TourCache|CoordinateNormalizer"
```

26 tests cover normalization, client mapping (incl. the verified schema),
cache, controller (200/202/401/422/404), and broadcasts.

### 6.2 Live API smoke test (real key, no auth needed)

Confirms TLS, the key, and the response-schema mapping end-to-end:

```powershell
php artisan tinker --execute="dump(app(App\Services\OpenStreetTspClient::class)->optimize([['lat'=>49.8998757,'lng'=>2.300284],['lat'=>48.4510104,'lng'=>6.7483327],['lat'=>48.7830011,'lng'=>2.333158],['lat'=>49.929876,'lng'=>1.078363]]));"
```

Expected: `ordered_stops` (4) + `total_distance_m` + `total_duration_s`.
A `cURL error 60` here means the CA bundle (§1.2) is not configured.

### 6.3 Full HTTP flow (needs login)

`composer dev` (+ a worker with `--timeout=1290`, §4), log in via browser, then
from devtools console:

```js
const csrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
const r = await fetch('/api/tour/optimize', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': csrf },
  body: JSON.stringify({ coordinates: [[49.8998757,2.300284],[48.4510104,6.7483327],[48.7830011,2.333158],[49.929876,1.078363]] }),
});
console.log(r.status, await r.json());   // 202 { status:'pending', job_uuid }
// then poll:
const res = await fetch('/api/tour/status/PASTE_JOB_UUID', { headers: { 'Accept': 'application/json' } });
console.log(await res.json());           // { status:'done', data:{...} }
```

Re-submitting the same coordinates → instant `200` (cache hit).
