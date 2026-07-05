# Contract: Responsive Main Bars

A UI contract (no network/API surface — this feature is presentation only).

## Shared style: `scroll-x-contained`

Defined once in `resources/css/app.css` as a Tailwind v4 `@utility`:

```css
@utility scroll-x-contained {
    overflow-x: auto;
    overscroll-behavior-x: contain;
}
```

**Behavior guarantees**
- Contents fit the width → no scrollbar, no visual change from today.
- Contents exceed the width → the element scrolls horizontally within its own box; children never render outside the box.
- Applies at every viewport width (no breakpoint / no JS toggle).
- Horizontal scroll does not chain to the page.

**Preconditions for safe reuse** (so this stays easy to apply to future interfaces without regressions)
- **Single-row / no intended vertical overflow.** `overflow-x: auto` makes the other axis compute to `auto` too (CSS spec: a non-`visible` axis forces the other non-`visible`). So the element must not rely on in-flow content bleeding *vertically* outside its box — an edge tooltip, badge, or an outer focus ring/shadow on an edge child would be clipped. Pop-up menus that portal to `document.body` (Radix `Select`) or are native (the date `<input>`) are unaffected. Apply this utility only to single-row strips.
- **Width must be constrained by the container.** The element must get its width from its parent (stretched in a flex-`col`, or given `min-w-0` inside a flex-`row`), not from its content. In an unconstrained flex-`row` a `scroll-x-contained` element could grow to its content width and re-introduce page overflow. Today's bars stretch inside a flex-`col`, so they are constrained.

## Bar application

### Editing-view control bar — `TourControlBar` root
- Carries `scroll-x-contained`.
- Its two child groups (the `Options` control group; the Optimize `ActionButton`) carry `shrink-0`.
- Fits-wide: mode + loop + date on the left, Optimize on the right (unchanged, via `justify-between`).
- Narrow: the whole bar scrolls sideways; mode, loop, date, and Optimize all reachable inside the bar.

### Result-view bar — `ResultSummary` header
- The `bg-primary` header bar carries `scroll-x-contained`.
- Its two child groups (the figures grid: Time on road / Tour duration / Mode / Date; and the New/Edit/Assign button group) carry `shrink-0`.
- Fits-wide: figures on the left, actions on the right (unchanged).
- Narrow: the bar scrolls sideways; all figures and the New, Edit, Assign actions reachable inside the bar.

## Verification surface

- **Automated (jsdom)**: assert each bar's root element carries the `scroll-x-contained` class. (jsdom has no layout engine, so actual scroll/overflow cannot be measured in unit tests.)
- **Manual (quickstart)**: at ~360px and ~320px widths, confirm each bar scrolls to expose every control, no control spills outside the rounded box, no whole-page horizontal scroll, and the desktop layout is unchanged.
