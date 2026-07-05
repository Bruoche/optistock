# Quickstart: Tour Code Refactor

## What this is
A backend-only, behavior-preserving cleanup of the tour-optimization pipeline: one job per layer (Controller → Service → Repository / Client), short intent-named methods, natural naming, no duplication. Zero behavior/endpoint change.

## The transparency contract
- The existing PHP test suite is the proof of no-regression. It MUST stay green with **no test logic changed** (a test may only be *retargeted* to a moved subject, same assertions; prefer none).
- Endpoint inputs/outputs are frozen — see `contracts/frozen-io.md`.
- Issues noticed but not fixed → `observations.md` (reviewed later, independently).

## Key files
- New: `app/Repositories/TourRepository.php`
- Refactored: `TourOptimizationController.php`, `TourPageController.php`, `OptimizeTourRequest.php`, `TourOptimizationService.php`, `TourRecorder.php`, `OptimizeTourJob.php`
- Untouched (noted only): `TourAssignmentController`, `DriverController`, `TourGeometryController`, all vendored/starter-kit code

## Verify (gate — all must pass, unchanged)
- `php artisan test` — full suite green; diff the test files to confirm no logic changed (only subject retargets allowed, ideally none).
- `./vendor/bin/pint --test` (or the project's PHP style check) + static analysis if configured — green.
- `npm run test`, `npm run lint`, `npm run types`, `npm run format:check` — still green (no frontend files touched; expected untouched).
- Sanity: `git diff --stat` shows only in-scope backend files + the new repository; no route, migration, or frontend changes.

## Watch out for
- **No behavior drift**: if a test would need its assertions changed to pass, stop — that's a behavior change, not a refactor.
- **No over-extraction**: only mutualise genuinely-shared logic; don't couple unrelated endpoints.
- **Stay in scope**: don't refactor earlier-feature controllers or vendored code — note them in `observations.md` instead.
