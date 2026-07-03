# Data Model — Projected Path Emphasis (015)

No database changes. This feature adds one boolean to the 014 `WorkdayLeg` payload shape and a
front-end render rule; the 013 schema and 014 chain assembly are untouched.

## Backend value object & service (changed)

### `WorkdayLeg` (`app/Services/WorkdayLeg.php`)

Gains one field:

| Field | Type | Meaning |
|---|---|---|
| `highlight` | `bool` | `true` only for a connection leg that brackets the candidate tour (the drive into its start, or the drive out of its end). All prior-tour connections and all tour legs are `false`. |

- Constructor gains `public readonly bool $highlight`.
- `connection(Coordinate $from, Coordinate $to, ?array $geometry, bool $highlight = false)` —
  new defaulted parameter.
- `tour(array $path, bool $loop)` — always constructs with `highlight: false` (a prior tour is
  never highlighted; the candidate tour is not a leg).
- `toArray()` — emits `'highlight' => $this->highlight` alongside the existing keys.

Every other field (`kind`, `dotted`, `path`, `geometry`, `loop`) is unchanged.

### `WorkdayLegsBuilder` (`app/Services/WorkdayLegsBuilder.php`)

- Private `connection(Coordinate $from, Coordinate $to, ?string $mode, bool $highlight = false)`
  threads the flag to `WorkdayLeg::connection`.
- In `build`, the two candidate-bracketing connection calls pass `highlight: true`:
  - `connection($previous, $candidateStart, $mode, highlight: true)`
  - `connection($candidateEnd, $warehouse, $mode, highlight: true)`
- The per-prior-tour connection (`connection($previous, $priorTour->start, $mode)`) keeps the
  default `false`.

**Invariant**: exactly the two candidate-adjacent connections are highlighted. With no prior
tours those are the only two legs, so both are highlighted (FR-004). Highlighting is independent
of geometry state.

## Frontend types (`resources/js/types/tour.ts`)

```ts
export type WorkdayLeg = {
    kind: 'connection' | 'tour';
    dotted: boolean;
    path: Array<[number, number]>;
    geometry: Array<[number, number]> | null;
    loop: boolean;
    /** True only for the connection drives bracketing the candidate tour;
     *  drawn in the primary role color at full opacity (feature 015). */
    highlight: boolean;
};
```

`use-tour-drivers` copies `legs` verbatim — no mapping change; the field arrives with the widened
type.

## Frontend rendering (`resources/js/components/tour/workday-layer.tsx`)

Per leg, driven solely by `highlight`:

| `highlight` | `line-color` | `line-opacity` |
|---|---|---|
| `true` | primary role color (`--primary`) | `1` |
| `false` | neutral role color (`--route-neutral`) | `0.5` |

`line-dasharray` stays keyed to `dotted` (unchanged). The primary color is resolved by a local
`primaryColor()` added to `WorkdayLayer`, mirroring its existing `neutralColor()`:

```ts
// beside the existing neutralColor() in workday-layer.tsx
const PRIMARY_FALLBACK = '#ff9a3c';
function primaryColor(): string {
    if (typeof window === 'undefined') return PRIMARY_FALLBACK;
    return getComputedStyle(document.documentElement)
        .getPropertyValue('--primary').trim() || PRIMARY_FALLBACK;
}
```

`RouteLayer` is unchanged — no shared helper is introduced (decision D3/R3).

### Existing WorkdayLeg literals (test compile fix)

`highlight` is a **required** field, so the full `WorkdayLeg` literals that already exist in the
014 tests must gain `highlight: false` or they stop compiling:

- `resources/js/components/tour/workday-layer.test.tsx` — the `leg()` factory default.
- `resources/js/hooks/use-workday-preview.test.ts` — the `leg()` factory default **and** the two
  inline connection literals (~L87, ~L155).

Default `false` also keeps the existing "paints every leg in the route-neutral role color"
assertion valid (its legs are non-highlighted).

## Invariants

- A leg's color and opacity are a pure function of its role flags (`highlight`), never of its
  geometry state — straight-line placeholder and traced road path render identically (FR-007).
- Colors are only ever the `--primary` / `--route-neutral` palette roles; no raw literal at point
  of use (Constitution VI). `0.5` is an opacity paint value, not a color.
- The candidate tour (`RouteLayer`, `--primary`, full opacity) is unchanged; the emphasis set is
  candidate tour + its two highlighted connections.
- Additive to the 014 payload: every existing leg field and every 013 driver field is unchanged.
