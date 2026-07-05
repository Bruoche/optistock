# Research: Mobile Scrollable Content Panel

## Decision 1 — Scope to mobile with Tailwind's `max-md:` variant (additive, desktop untouched)

**Decision**: Express every mobile change as a `max-md:` utility (applies below 768px). Leave all current classes exactly as they are; the `max-md:` variants only add behavior on phones.

**Rationale**: The user's hard constraint is "mobile-only, don't touch desktop, use conditional CSS." Tailwind is mobile-first, so a plain rewrite would push current classes into `md:` and risk changing desktop. `max-md:` inverts that: the existing (desktop) classes remain the base and stay literally unchanged, and phones get an additive override. It is pure CSS — no JS breakpoint hook, no hydration flash. 768px is the app's own mobile breakpoint (`use-mobile.tsx` `MOBILE_BREAKPOINT = 768`, i.e. Tailwind `md`), so behavior lines up with the rest of the app.

**Alternatives considered**:
- *`useIsMobile()` JS hook + conditional classes*: adds runtime, a listener, and a hydration/first-paint branch for something CSS does natively. Rejected.
- *Rewrite mobile-first (`md:` for desktop)*: would edit the desktop classes, contradicting "don't impact desktop" and risking regressions. Rejected.

## Decision 2 — Make the panel a single scroll surface on mobile

**Decision**: On the bottom content panel (`optimize.tsx`), add `max-md:overflow-y-auto` (overriding the base `overflow-hidden`). Relax the height constraints inside so the content is natural height and overflows the panel: the content wrappers (`ResultSummary` / `StopList` roots, currently `h-full`) get `max-md:h-auto`, and the inner lists (`DriverList` / `StopList` `<ul>`, currently `flex-1 … overflow-y-auto`) get `max-md:flex-none`.

**Rationale**: Today the panel is a fixed-height box (`overflow-hidden`) split into a fixed bar + a `flex-1` internally-scrolling list. When the bar wraps tall on mobile it eats the panel and the list's area collapses to ~0 — the reported bug. Switching the panel to `overflow-y-auto` and letting its content be natural height makes the whole panel (bar + list) one scroll surface: the bar scrolls up, the list follows and is fully reachable. `max-md:flex-none` stops the list from trying to fill and self-scroll; because it is then natural height, its own `overflow-y-auto` never triggers (nothing to override there).

**Alternatives considered**:
- *Give the inner list a fixed/min height on mobile*: brittle magic numbers; still leaves two nested scroll areas. Rejected.
- *Collapse/短en the bar on mobile*: changes the just-shipped wrap behavior and hides controls. Rejected.

## Decision 3 — "Behind the map" needs no z-index or translucency

**Decision**: The bar disappearing "behind the map" is achieved for free by the panel's `overflow-y-auto`: as the panel scrolls, content above the panel's top edge is clipped. The map is a separate flex sibling directly above the panel (with the `border-t` between them), so the clip line sits right under the map — the bar visually vanishes beneath the map boundary.

**Rationale**: The map and panel are stacked flex regions that never overlap, so nothing is ever drawn on top of the map (FR-002). The user's phrase "behind / through the map" is satisfied by clipping at the shared boundary — no stacking context, z-index, or opacity is required.

**Alternatives considered**:
- *Overlay the panel under the map with negative margins / z-index*: unnecessary complexity for the same visual. Rejected.

## Decision 4 — Edge-to-edge bar via `max-md:p-0` + `max-md:rounded-none`

**Decision**: Remove the panel's outer padding on mobile (`max-md:p-0`, overriding `p-4`) so the orange bar reaches the screen edges, and drop the bar's corner rounding on mobile (`max-md:rounded-none` on the two bars) so it is flush with no background showing at the corners.

**Rationale**: The "small black borders all around the box" are the `p-4` strip of page background framing the bar; `max-md:p-0` removes it. With zero padding the rounded corners would still leave four tiny background arcs, so `max-md:rounded-none` completes the edge-to-edge look. The bar's *internal* padding (around its controls) is untouched. Desktop keeps `p-4` and `rounded-md`.

**Alternatives considered**:
- *Only remove padding, keep rounding*: leaves faint corner background — the user explicitly dislikes borders around the box. Included rounding removal for a clean result.
- *Negative margins to bleed the bar past the panel padding*: hackier than just dropping the padding on mobile. Rejected.

## Decision 5 — Verification is class-presence + manual (jsdom limitation)

**Decision**: Unit tests assert the `max-md:` override classes exist on the right elements; the real scroll / clipping / edge-to-edge is confirmed by the quickstart at phone widths.

**Rationale**: jsdom has no layout engine and does not evaluate media queries, so responsive/scroll behavior cannot be measured in unit tests. Guarding class presence catches accidental removal; the visual behavior is inherently a manual check.
