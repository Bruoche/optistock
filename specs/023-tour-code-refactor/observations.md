# Observations — Deferred (NOT acted on in this refactor)

Per the user's instruction, behavior / robustness / optimization issues noticed while refactoring are recorded here and **left untouched**, so each can be reviewed and scheduled independently without mixing into the no-regression readability pass. Nothing in this list is changed by feature 023.

## Behavior / robustness

- **O1 — `OptimizeTourRequest::authorize()` maps a null user to 404.** `authorize()` returns `false` when `user()` is null, and `failedAuthorization()` throws `NotFoundHttpException` (404). Unauthenticated requests are already blocked by route middleware (401), so this path is unreached today — but the 404-for-null-user is a latent inconsistency. *Defer*: decide 401 vs 404 for the (currently unreachable) no-user case separately.

- **O2 — Ownership lookup runs twice on the optimize edit path.** `OptimizeTourRequest` loads the tour to authorize, then the service/recorder loads it again to update. Harmless (cheap, transactional) but a redundant read. *Defer*: possible single-load optimization — but it touches behavior/perf, so not now.

## Deferred to a separate pass

- **Front-end refactor (spec FR-002).** Single-sourcing the shared "orange bar" and the bar-plus-scrollable-list panel is a **front-end** cleanup. This feature (023) is **back-end only**; the front-end pass is deferred and scheduled separately. Not touched under branch `023`.

## Now in scope (moved from deferred → tasks)

- `TourAssignmentController::assign` role fix and `DriverController::available` role fix are **no longer deferred** — the scope was widened to the whole route-optimization back-end, so they are addressed by tasks in `tasks.md` (extract services + `DriverTourRepository`). Kept here only as a pointer.

## Notes

- These are **candidates**, not commitments. Add to this file if more surface during implementation; do not fix O1/O2 (behavior/robustness) under branch `023`.
- Anything requiring a behavior change, a new test, or a perf trade-off belongs here, not in the refactor.
