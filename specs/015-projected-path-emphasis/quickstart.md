# Quickstart — Projected Path Emphasis (015)

Verifies the emphasized preview end to end. Builds on the 014 workday preview.

## Prerequisites

- A driver with **at least one prior tour** assigned for the selected date (so the preview has
  both highlighted and dimmed legs), and a driver with **no** prior tours (edge case).
- An optimized candidate tour on the presentation phase.

## Happy path — a driver with prior tours

1. On the presentation phase, click the driver holding a prior tour.
2. **Expect** the projected workday to render with two emphasis tiers:
   - The candidate tour and the two connection drives flanking it (into its start, out of its end)
     in **primary orange at full opacity** — one continuous orange shape.
   - Every prior tour (solid) and every pre-existing connection (dotted) in the **neutral color at
     50% opacity**, receded behind the orange path.
3. Confirm the two orange connections are still **dotted** (only their color/opacity changed).
4. Confirm you can read, by color alone, how much extra travel the candidate tour adds between the
   existing tours / warehouse (SC-001, SC-002).

## Progressive upgrade keeps emphasis (FR-007)

1. Select a driver whose candidate connections start as straight-line placeholders.
2. As road geometry arrives and replaces a straight line, **expect** the segment to keep its exact
   color and opacity — a highlighted connection stays primary/opaque, a dimmed segment stays
   neutral/50% (SC-003). No flash, no tier change.

## Edge — a driver with no prior tours

1. Click the driver with no prior tours for the date.
2. **Expect** both connection drives (warehouse → candidate, candidate → warehouse) drawn in
   primary orange at full opacity — the whole preview is the emphasis set; nothing is dimmed
   (FR-004).

## Rapid cycling (no regression — SC-004)

1. Click quickly through several drivers, mixing ones with and without prior tours.
2. **Expect** each shown preview to carry the correct emphasis for that driver, no leftover
   segments, and no error — identical stability to 014, now with the two-tier styling.

## Automated checks

- Backend: `php artisan test --filter=WorkdayLegsBuilderTest` (highlight positions, no-prior
  case) and `--filter=DriverAvailabilityTest` (`highlight` in the payload).
- Frontend: `npm run test -- workday-layer` (primary+opaque highlighted leg, neutral+0.5
  dimmed leg, dash unaffected).
