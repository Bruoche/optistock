# Contract: Mobile Content Panel

A UI contract (no network/API surface — presentation only). All rules are scoped to viewports < 768px via Tailwind's `max-md:` variant; at ≥ 768px nothing here applies.

## Panel (bottom content region, `optimize.tsx`)
- Base (kept): `flex min-h-0 flex-1 flex-col gap-3 overflow-hidden border-t border-border p-4`
- Add: `max-md:overflow-y-auto max-md:p-0`
- Guarantee: on mobile the panel is a single vertical scroll surface with no outer padding (its whole content — bar AND list rows — is full-bleed to the edges) and no horizontal scroll (base `overflow-hidden` keeps `overflow-x: hidden`; only `overflow-y` becomes `auto`); on desktop it is the current fixed, padded, `overflow-hidden` box.

## Content wrappers (`ResultSummary` root, `StopList` root)
- Base (kept): `flex h-full flex-col gap-3`
- Add: `max-md:h-auto`
- Guarantee: on mobile the wrapper is content-height (so it overflows the panel and the panel scrolls it); on desktop it fills the panel as today.

## Lists (`DriverList` `<ul>`, `StopList` `<ul>`)
- Base (kept): `… flex-1 … overflow-y-auto` (DriverList also `min-h-0`)
- Add: `max-md:flex-none`
- Guarantee: on mobile the list is natural height and part of the single panel scroll (its own `overflow-y-auto` never triggers since it no longer fills); on desktop it fills its area and scrolls internally.

## Bars (`TourControlBar` root, `ResultSummary` header bar)
- Base (kept): `… rounded-md bg-primary …` (already `flex-wrap` from feature 021)
- Add: `max-md:rounded-none`
- Guarantee: on mobile the bar is flush edge-to-edge (with `max-md:p-0` on the panel) — no background border/arcs around it; on desktop it keeps its rounded, inset look.

## Behavioral guarantees
- **Scroll to reach the list**: on mobile, scrolling the panel moves the bar up and brings the full list into reach (FR-001, SC-001).
- **Behind the map**: the bar is clipped at the panel's top edge, beneath the fixed map region; it never renders above that boundary or over the map (FR-002, SC-003). No z-index/opacity involved.
- **Edge-to-edge**: the bar touches the panel's side edges with no framing background (FR-003, SC-002).
- **Desktop unchanged**: none of the `max-md:` variants apply at ≥ 768px (FR-004, SC-004).

## Verification surface
- **Automated (jsdom)**: assert each element carries its `max-md:` override class. jsdom has no layout engine and does not evaluate media queries, so scroll/clipping/edge-to-edge cannot be measured in unit tests.
- **Manual (quickstart)**: at ~360px confirm the panel scrolls, the bar disappears under the map, the bar is edge-to-edge, and every list item is reachable; at desktop width confirm the layout is unchanged.
