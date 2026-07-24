# Phase 0 Research: Drivers Directory

No open `NEEDS CLARIFICATION` remained after the spec + user directive. The decisions below
resolve the design choices this feature makes.

## Decision 1 — Filter server-side in a new endpoint (not client-side)

- **Decision**: Add one new endpoint `GET /api/drivers` that accepts the three criteria as query
  parameters and returns the matching, name-sorted drivers. The frontend re-fetches on criterion
  change; it does **not** fetch-all-then-filter in the browser.
- **Rationale**: The user directive for feature 027 is explicit — "make a new end-point to the back
  to send the drivers according to the search criterias." Server-side filtering keeps one authority
  for the AND-mode / partial-name / warehouse semantics, matches the existing pattern where the
  backend owns driver selection (`Driver::scopeAvailable`), and keeps the payload to exactly the
  rows shown.
- **Alternatives considered**:
  - *Fetch all drivers once, filter in React.* Rejected per the directive; it would also split the
    filter semantics between front and back and re-implement mode-AND / case-insensitive matching in
    TypeScript.
  - *Extend `GET /api/tour/drivers` (`DriverController::available`).* Rejected — that endpoint is
    tour-scoped (requires `mode` + `date` + `tour`, runs routing/workday projection) and its I/O is
    frozen. Reusing it would violate "no impact to existing endpoints" and pull in irrelevant
    computation.

## Decision 2 — Meeting the 300ms responsiveness target with a network round-trip

- **Decision**: Debounce the name input (~200ms) and fire an immediate fetch on discrete changes
  (mode toggle, warehouse change), each new fetch cancelling the previous one; the hook only commits
  the response whose criteria still match the current criteria.
- **Rationale**: The driver set is small and the query is a single indexed read on the same session
  host, so a round-trip is well under budget; debounce collapses fast typing into one request, and
  cancellation guarantees the list settles on the latest criteria (FR-015, SC-004) with no stale or
  flickering result — the exact pattern already proven in `use-driver-day` / `use-tour-drivers`.
- **Alternatives considered**: No debounce (a request per keystroke — wasteful, race-prone);
  client-side filtering to dodge the network entirely (rejected in Decision 1).

## Decision 3 — Backend layering

- **Decision**: `DriverDirectoryController::index(DriverDirectoryRequest) → DriverDirectoryService::
  search(criteria): Collection<DirectoryDriverData>`; the query lives in a new
  `Driver::scopeMatching(name, modes, warehouseId)` beside `scopeAvailable`; the row shape lives in
  a `DirectoryDriverData` DTO with `toArray()`.
- **Rationale**: Mirrors the established `DriverController::day → DayWorkdayService → DriverDayData`
  layering (Controller thin, Service owns the query, DTO owns the payload) and keeps SRP. A query
  scope keeps the Eloquent filter next to the existing driver scope, self-documenting.
- **Alternatives considered**: Building the query inline in the controller (violates
  Controller→Service role separation and the SRP the 023 refactor established); a repository method
  (heavier than needed — this is a single read with no write side).

## Decision 4 — Case-insensitive partial name match

- **Decision**: Match with `whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])` after
  trimming; an empty/whitespace-only term adds no clause.
- **Rationale**: Explicit and portable — does not rely on the column collation being case-insensitive
  (SQLite's `LIKE` is ASCII-only case-insensitive; MySQL depends on collation). One place, one rule,
  matches the "'cha' → Sacha/Charline/Hector" example in the spec.
- **Alternatives considered**: `where('name','like',...)` (collation-dependent, non-portable across
  the SQLite test DB and MySQL); full-text search (overkill for a substring contains).

## Decision 5 — AND semantics for required modes

- **Decision**: For each selected mode, add a `whereHas('deliveryModes', label = mode)` clause; the
  clauses AND together, so only drivers possessing **every** selected mode remain. Zero selected →
  no clause (all pass).
- **Rationale**: Matches FR-009 exactly and is the natural Eloquent expression of "must have all".
- **Alternatives considered**: A single `whereHas ... whereIn(labels)` with a group-count (OR-ish,
  would need a `having count = n` — more complex than repeated `whereHas`).

## Decision 6 — Row presentation reuse (no duplicated visual rule)

- **Decision**: Extract the identity block currently inlined in `driver-list.tsx` (avatar/placeholder
  + name + mode icons + warehouse line) into a shared `DriverSummary` presentational component;
  `driver-list.tsx` renders `<DriverSummary>` then its existing figures; the directory row renders
  `<DriverSummary>` alone inside a link.
- **Rationale**: The spec requires the directory row be "presented consistently with the driver list
  on the tour-assignment page" (FR-003); Constitution VI forbids duplicating the same visual rule.
  Extraction is behavior-preserving for the assignment list (same markup, just relocated) and gives
  the directory the identical look for free.
- **Alternatives considered**: Copy-adapt the markup into a separate directory row (feature 025's
  earlier approach) — rejected here because it duplicates the exact same presentation the spec wants
  kept in lock-step, which Constitution VI prohibits.

## Decision 7 — Warehouse options source & driver scope

- **Decision**: The web route `GET /drivers` (`DriverPageController::directory`) passes a `warehouses`
  prop (`id`, `name`, name-ordered) exactly as `manage()` already does. Drivers/warehouses are global
  (no `user_id` on `drivers`), so the directory lists all drivers to any authenticated, verified user
  — consistent with the existing driver/tour pages.
- **Rationale**: Reuses the identical prop shape and access rule already in place; the modes list
  comes from the frontend `DELIVERY_MODES` constant (the enum carries no labels), same as feature 025.
- **Alternatives considered**: Fetching warehouses via a second XHR (unnecessary — they are static
  page data); scoping drivers per user (they are not user-owned).
