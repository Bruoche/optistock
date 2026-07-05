# Quickstart: Edit Tour

## What this feature does
Re-open an optimized-but-unassigned tour, tweak it, and re-optimize so the **same** tour is updated — no duplicate. Result-view actions become **New · Edit · Assign**.

## Try it
1. Optimize a tour (place stops → Optimize). Note the result view.
2. Action row now reads **New · Edit · Assign**. Click **Edit**.
3. The page returns to the editing menu, pre-filled with the tour's stops, per-stop durations, mode, and loop.
4. Move/add/remove a stop or change mode/loop, then Optimize again.
5. Confirm the result reflects the change and that only one tour exists for this route (no duplicate).

## Key files
- Backend pipeline (additive `tour_id`): `OptimizeTourRequest.php`, `TourOptimizationController.php`, `TourOptimizationService.php`, `OptimizeTourJob.php`, `TourRecorder.php`
- New page controller + route: `TourPageController.php`, `routes/web.php`
- Frontend: `pages/tour/optimize.tsx`, `hooks/use-tour-optimization.ts`, `components/tour/result-summary.tsx`, `types/tour.ts`

## Verify (CI gate — run all before done)
- `./vendor/bin/pest` — Feature: optimize with `tour_id` updates the same row (count unchanged); assigned/foreign `tour_id` rejected (422/404); edit route hydrates stops/mode/loop. Unit: `TourRecorder` update path replaces stops.
- `npm run test` — button row is New/Edit/Assign; edit navigation; page hydrates from `editTour` prop and sends `tour_id`.
- `npm run lint`, `npm run types`, `npm run format:check` — all green.

## Watch out for
- Editing is **unassigned-only** — assigned tours are not editable (server rejects).
- Date is not restored (unassigned tours have no date) — it defaults to today, by design.
- The queued path must never silently create a new tour if the edit target vanished — it surfaces `persist_failed`.
