# Research: Mobile Responsive Interface

## Decision 1 — Contain overflow with `overflow-x: auto` on each bar (native CSS, always-on)

**Decision**: Give each main bar `overflow-x: auto` (via a shared utility). When the bar's contents fit, `auto` shows no scrollbar and the bar is pixel-identical to today; when they overflow, the bar becomes a horizontally scrollable strip and clips its contents to its own rounded box.

**Rationale**: This is exactly the user's directive — "keep the non-overflowing state exactly the same, make elements scrollable if overflowing, and stop elements exiting their boxes." `overflow-x: auto` is the one-property, zero-JS way to get all three: no scrollbar when it fits, scroll when it doesn't, and containment (the box clips instead of the children spilling out). It is always available at every width, with no breakpoint or toggle (spec FR-007, and the user's "no matter what").

**Alternatives considered**:
- *JS viewport/`use-mobile` breakpoint that swaps layouts*: adds runtime, listeners, and a second code path; would risk changing the fits state and contradicts "always available." Rejected.
- *Wrap/stack the controls on small screens (`flex-wrap`)*: changes the layout (taller bar, reflowed controls) rather than keeping it identical + scrollable; the user explicitly chose scrolling. Rejected.
- *`overflow-x: scroll`*: always shows a scrollbar track, altering the fits state. Rejected in favor of `auto`.

## Decision 2 — Keep child groups at intrinsic width with `shrink-0`

**Decision**: Mark each bar's immediate child groups `shrink-0` (the control group + Optimize button; the figures grid + the New/Edit/Assign buttons) so they keep their natural size and the bar scrolls, rather than the flex children compressing to min-content before overflow.

**Rationale**: Flex items default to `flex-shrink: 1`, so as the bar narrows the controls would squish (misaligned/cramped) before the bar ever overflows. `shrink-0` keeps controls at their intrinsic size so narrowing goes straight to a clean scroll. This does not change the fits-wide state: with `justify-between` and enough room, there is free space to distribute exactly as now.

**Alternatives considered**:
- *Do nothing (leave shrink:1)*: controls would deform at medium widths — ugly and can hide the overflow the user reported. Rejected.
- *Restructure each bar into a single `w-max` inner track*: more DOM churn than needed; `shrink-0` on the existing two groups is smaller and preserves current markup.

## Decision 3 — Single reusable utility in `app.css` (`scroll-x-contained`)

**Decision**: Define one Tailwind v4 `@utility scroll-x-contained { overflow-x: auto; overscroll-behavior-x: contain; }` in `resources/css/app.css` and apply it to both bar roots.

**Rationale**: Constitution VI requires recurring styling to be factored into a shared, well-named single source so a change propagates from one place. Both bars need the identical behavior; a named utility states intent and avoids duplicating the rule. `overscroll-behavior-x: contain` stops a bar's horizontal scroll from chaining to the page (reinforces "the page doesn't scroll horizontally," FR-004).

**Alternatives considered**:
- *Inline `overflow-x-auto` on each bar*: two copies of the behavior; weaker against VI and less intent-revealing than a named style. Rejected (though functionally equivalent).
- *A shared `<Bar>` wrapper component*: the two bars also duplicate base classes, but extracting a full component is broader than this feature's scope; deferred.

## Decision 4 — No clipping risk for the bars' pop-up menus

**Decision**: Applying `overflow` to the bars is safe for their interactive menus: `ModeSelect` uses Radix `SelectContent`, which portals to `document.body`, and the date control is a native `<input type="date">` whose picker is a native OS popup. Neither is a DOM descendant clipped by the bar's `overflow`.

**Rationale**: The usual hazard of adding `overflow` to a container — clipping a dropdown that renders inside it — does not apply here, because both controls render their menu outside the bar's box. Verified in `mode-select.tsx` and `tour-date-field.tsx`. So overflow containment costs nothing in menu usability (spec edge case).

**Alternatives considered**: none needed — verification confirmed no in-flow popovers.

## Decision 5 — Page-level horizontal overflow already prevented

**Decision**: US2's "no whole-page horizontal scroll" is largely already satisfied: the tour page's content column is `overflow-hidden`, which today clips the spilling controls (making them unreachable — the bug) and also blocks page-level horizontal scroll. Fixing the bars (Decisions 1–2) turns that clipping into in-bar scrolling. We verify no other element forces page width; no new page-level rule is expected.

**Rationale**: The reported problem is the bars, not the page frame. Keeping the existing `overflow-hidden` column and adding in-bar scroll resolves both the unreachable-controls bug and page overflow with the minimal change.

**Alternatives considered**:
- *Add `overflow-x-hidden` to the page root defensively*: only if verification finds a leak; not adding speculative rules.
