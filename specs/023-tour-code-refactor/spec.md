# Feature Specification: Tour Code Refactor

**Feature Branch**: `023-tour-code-refactor`

**Created**: 2026-07-05

**Status**: Draft

**Input**: User description: "The feature is now in a satisfactory state, we are now going to refactor the code to ensure it is clean, robust and readable for future maintenance."

## Clarifications

### Session 2026-07-05

- **This is a purely back-end refactor.** The front-end cleanup originally implied here (single-sourcing the shared "orange bar" and the bar-plus-scrollable-list panel) is **explicitly deferred** to a later, separate pass and is out of scope for this feature.
- **Scope = the whole route-optimization back-end that is our code.** All backend work built so far serves the one overarching route-optimization feature, so the refactor covers all of it: the tour-optimization + edit pipeline, driver availability / workday projection (017–019), tour assignment (012–013), and route geometry (002) — controllers, form requests, services, jobs, and the API clients. Vendored / starter-kit code is never in scope.
- This is a **behavior-preserving** refactor: no user-visible behavior, routes, data, or API responses change. The existing automated test suite is the safety net and MUST stay green without being weakened (a test may only be *retargeted* to a moved subject with identical assertions; no new tests, no weakened tests).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A maintainer reads the tour code and it is self-evident (Priority: P1)

A developer returns to the tour optimization code months later to make a change. Instead of puzzling over duplicated layout strings, near-identical component structures, and drifting patterns, they find the recurring pieces factored into shared, well-named building blocks, consistent naming, and only high-signal comments. They can locate the right place to change, make the change once, and trust it propagates — quickly and with low risk.

**Why this priority**: Readability and low duplication are the core of "clean and maintainable" and the primary reason for the refactor; they deliver the maintenance speed-up even if nothing else is touched.

**Independent Test**: Review the touched back-end files and confirm recurring logic (e.g. tour/stop persistence + ownership lookups, driver-tour data queries, workday/connection assembly) is expressed once through a shared, correctly-layered abstraction, each long method reads as short named steps, names are business-clear — while every existing test still passes unchanged.

**Acceptance Scenarios**:

1. **Given** the tour/stop persistence and "find the user's tour" logic duplicated across the recorder, a controller, and a form request, **When** the refactor is complete, **Then** that data access lives in one repository so it changes in a single place.
2. **Given** a controller that today holds domain orchestration and direct database queries (e.g. driver availability, tour assignment), **When** the refactor is complete, **Then** the logic lives in a service and the data access in a repository, leaving the controller to translate the request only.
3. **Given** any comment in the touched code, **When** reviewed, **Then** it records only a non-inferable constraint (business rule, external limitation, deliberate placeholder) and never narrates what the code already says.
4. **Given** the full refactor, **When** the test suite runs, **Then** all existing tests pass without modification (behavior unchanged).

---

### User Story 2 - The refactored code is robust and free of dead weight (Priority: P2)

A reviewer checks the touched code and finds failure paths handled explicitly and consistently, no unused/dead code (such as leftover imports), and predictable, easy-to-trace state — so latent bugs and confusion are removed along with the clutter.

**Why this priority**: Robustness and dead-code removal harden the code and remove noise, but the readability/duplication work (US1) is the headline maintenance win; this complements it.

**Independent Test**: Review the touched code for unused symbols, silent failure paths, and inconsistent error handling; confirm each is removed or made explicit, with the test suite still green.

**Acceptance Scenarios**:

1. **Given** an unused import or otherwise dead symbol in a touched file, **When** the refactor is complete, **Then** it is removed.
2. **Given** a failure path in the touched code, **When** reviewed, **Then** it is surfaced/logged rather than swallowed, consistent with the project's robustness rules.
3. **Given** the refactor, **When** the full CI gate runs (tests, lint, type-check, formatting), **Then** it is green.

---

### Edge Cases

- **A refactor that would change behavior**: out of scope — anything that alters a user-visible outcome or an API response is not part of this work; only structure/readability/robustness change.
- **Tests that would need changing to pass**: a change that forces weakening or rewriting an existing behavioral test signals a behavior change and must be reconsidered (tests may be *added*, not weakened).
- **Shared abstraction overreach**: extracting a shared piece must not couple unrelated call sites or hide meaningful differences; if two usages are only superficially similar, they stay separate.
- **Scope creep into legacy code**: files outside the recent tour work are not refactored unless a touched file directly depends on them.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The refactor MUST NOT change any user-visible behavior, route, persisted data, or API response — it is structural only.
- **FR-002**: (Deferred — front-end only) Recurring **front-end** styling/structure (the shared "orange bar" and the bar-plus-scrollable-list panel) will be single-sourced in a **separate later pass**; it is out of scope for this back-end refactor and has no task here.
- **FR-003**: Duplicated logic in the touched code MUST be consolidated into shared, well-named units; superficial similarities that would couple unrelated code MUST be left separate.
- **FR-004**: Names in the touched code MUST be descriptive and unambiguous at the minimum verbosity needed; comments MUST be reduced to only non-inferable constraints and MUST NOT narrate self-evident code.
- **FR-005**: Dead or unused code (unused imports, unreachable branches, orphaned symbols) in the touched files MUST be removed.
- **FR-006**: Failure paths in the touched code MUST be explicit and consistent — surfaced or logged, never silently swallowed.
- **FR-007**: The existing automated test suite MUST pass unchanged; tests MAY be added to lock in behavior, but existing behavioral tests MUST NOT be weakened to accommodate the refactor.
- **FR-008**: The full CI gate — tests, linting, type-checking, and formatting — MUST be green after the refactor.
- **FR-009**: The refactor scope MUST cover the whole route-optimization **back-end** that is our code (tour-optimization + edit pipeline, driver availability / workday projection, tour assignment, route geometry, and the API clients) and MUST NOT touch vendored / starter-kit code or any front-end file.

### Key Entities

- Not applicable — this is an internal code-quality change with no data model, entities, or new interfaces.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of the pre-existing automated tests pass after the refactor with no existing behavioral test modified (behavior preserved).
- **SC-002**: Each previously-duplicated back-end concern (tour/stop persistence, "find the user's tour" ownership lookup, driver-tour queries) has a single source; changing one requires editing exactly one place (verified by inspection — no duplicated copies remain across controllers / requests / services).
- **SC-003**: Zero unused imports or dead symbols remain in the touched files (linter/type-checker reports none).
- **SC-004**: The full CI gate (tests, lint, type-check, formatting) passes green.
- **SC-005**: Every comment remaining in the touched code records a non-inferable constraint (no narrating comments), confirmed by review.
- **SC-006**: A maintainer can point to one location for each previously-duplicated pattern, demonstrating the change-once property.

## Assumptions

- "The feature" = the overarching route-optimization product; every backend piece built so far is part of it, so the refactor's scope is the whole route-optimization **back-end** (our code only). The front-end refactor is a separate, later, deferred pass.
- The refactor is strictly behavior-preserving; the existing test suite is the correctness guardrail and stays green unmodified (additions allowed).
- The work is guided by the project constitution — readability by default, minimal high-signal comments, simple and transparent design, robustness as standard, and consistent reusable front-end styling (single-source colors and shared style classes/components).
- No new user-facing behavior, routes, endpoints, or data are introduced.
- Where a prior feature's design notes already flagged a deferred cleanup (e.g. extracting a shared bar/panel component that two surfaces duplicate), that cleanup is fair game for this refactor.
