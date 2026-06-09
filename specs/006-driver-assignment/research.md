# Research: Delivery Driver Assignment

## R1 — Driver ↔ delivery-mode relationship shape

**Decision**: Many-to-many — `drivers`, a shared `delivery_modes` lookup (3 fixed rows), and a
`driver_delivery_mode` pivot; Laravel `belongsToMany`.

**Rationale**: The spec asks for a shared "enum table" (autoincrement id + string label) whose labels match
the app's modes, *and* for a driver to support one or more modes. A shared 3-row lookup referenced by many
drivers, each linking 1–2 of those rows, is by definition many-to-many. The user confirmed this over a literal
one-to-many.

**Alternatives considered**:
- *Literal one-to-many* (`delivery_modes.driver_id`): each mode row owned by one driver → no shared enum,
  duplicated rows per driver, labels drift. Rejected — contradicts the "shared enum table" intent.
- *No table; store modes as a JSON/set column on `drivers`*: simplest, but discards the requested enum table
  and the relational integrity (FK) that makes future mode-based queries clean. Rejected per explicit spec.

## R2 — Enum vs. lookup table, and model naming

**Decision**: Keep `App\Enums\DeliveryMode` as the authoritative mode set; name the new Eloquent model
`App\Models\DeliveryModeOption`. Seed `delivery_modes.label` with the enum's backing values
(`trucking`/`driving`/`walking`).

**Rationale**: The enum already drives optimize/geometry requests and is the single source of allowed modes.
The table is a persistence mirror enabling the FK relationship. Two classes literally named `DeliveryMode`
(enum + model) would be ambiguous at every import site, against the project's naming philosophy; `…Option`
names the persisted lookup row clearly. Mirroring the enum values means the frontend filters/labels with the
same strings it already has — no mapping layer.

**Alternatives considered**:
- *Name the model `DeliveryMode` (Models namespace)*: most "default" Laravel, but collides in meaning with the
  enum. Rejected for clarity.
- *Cast `label` to the enum on the model*: nice, but unnecessary for this feature's read path; can be added
  later. Deferred.

## R3 — Driver image handling ("idiomatic Laravel")

**Decision**: `drivers.image_path` (nullable) on the `public` disk; model exposes an `image_url` accessor via
`Storage::disk('public')->url()`. Null path → `image_url: null` → frontend placeholder.

**Rationale**: The standard Laravel pattern for user-visible files is the `public` disk + `Storage::url()`,
already configured in `config/filesystems.php`. It is upload-ready without building an upload UI now (drivers
are seeded this feature). The placeholder fallback satisfies FR-008 and keeps rows well-formed.

**Alternatives considered**:
- *Store a full external URL string*: inflexible, no disk management. Rejected.
- *Build an upload UI now*: out of scope (no driver management this feature). Deferred.

## R4 — Request pattern for the driver list

**Decision**: A plain authenticated `GET /api/tour/drivers?mode=<mode>` returning JSON, fetched by a small
frontend hook when a result is shown — mirroring the existing `/api/tour/*` + `fetch` pattern.

**Rationale**: The tour UI already speaks to `/api/*` JSON endpoints via `fetch` (see
`use-tour-optimization.ts`), authenticated by the session cookie under the `web` group. A `GET` with a `mode`
query parameter is the idiomatic, cacheable read for "the ordered list of available drivers". Consistency with
the existing flow beats introducing Inertia props for this transient, result-scoped list.

**Alternatives considered**:
- *Inertia page props*: the tour page is a long-lived SPA screen driven by `fetch`, not per-request props;
  threading drivers through props would fight the existing architecture. Rejected.
- *Include drivers in the optimize response*: couples driver lookup to the (cached, queued) optimize path and
  recomputes on every cache hit; a separate read is cleaner and refreshes independently on re-optimize.
  Rejected.

## R5 — Ordering

**Decision**: Alphabetical by `name` ascending, server-side.

**Rationale**: The spec explicitly defers criteria-based ordering ("later… specific criteria… out of scope")
and says to stick to alphabetical for now. Ordering server-side keeps the contract stable and the frontend
dumb.

## R6 — Mode icons

**Decision**: `walking` → person/footprints, `driving` → car, `trucking` → truck (lucide-react), via a single
`MODE_ICON` map keyed by `DeliveryMode`.

**Rationale**: lucide-react is the project's existing icon set; a shared map keeps the icon choice in one place
and consistent with `DELIVERY_MODES`, satisfying the constitution's reuse rule and FR-006.
