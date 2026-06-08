<!--
Sync Impact Report
Version change: 1.2.0 → 1.3.0
Modified principles: II. Readable by Default — added minimal-comment / self-evident-code discipline
Added sections: none
Removed sections: none
Templates requiring updates:
  - .specify/templates/plan-template.md ✅ reviewed (generic Constitution Check; no change needed)
  - .specify/templates/spec-template.md ✅ reviewed (no change needed)
  - .specify/templates/tasks-template.md ✅ reviewed (no change needed)
Follow-up TODOs: none
-->

# Optistock Constitution

## Core Principles

### I. Quality First
Every change MUST preserve and improve code quality. Code MUST be complete, consistent, and supported by automated validation; tests and review gates are required for behavior that affects correctness, maintainability, or system health.

### II. Readable by Default
Code MUST be easy to read and understand for any team member. Prefer clear names, explicit intent, simple control flow, and minimal cognitive load; avoid cleverness, hidden behavior, and dense one-liners.

Code MUST be self-evident through its syntax and naming rather than through narration, and comments MUST be kept to a minimum:

- An in-body (mid-function) comment is permitted ONLY to record a genuine constraint a reader could not infer from the code itself: a business rule or external/technical limitation that forces an otherwise odd approach (e.g. an imposed optimization, or logic that demonstrably cannot be simplified), or a marker that a value or branch is a deliberate placeholder to be implemented later.
- Comments MUST NOT restate what the code already says or narrate self-evident steps; such padding of otherwise simple, evident functions is prohibited.
- Clarity MUST first be pursued through descriptive variable and function names, kept to the minimum verbosity needed to be unambiguous — neither abbreviation-cryptic nor needlessly long.

Rationale: self-evident code stays correct as it changes, while narrating comments drift out of date and add noise; reserving comments for the rare non-obvious constraint keeps the remaining ones high-signal.

### III. Simple & Transparent
Design MUST favor the simplest solution that solves the problem correctly. Complexity is only acceptable when it is necessary, explicitly justified, and documented. Each module, class, and function MUST have a single clear responsibility.

### IV. Robustness as Standard
Robust code MUST validate inputs, handle failure modes explicitly, and preserve invariants. Errors MUST be surfaced clearly and safely rather than hidden, and defensive checks MUST protect production behavior without sacrificing clarity.

The application MUST NEVER fail silently. Every failure path MUST be logged with enough context to diagnose it (the operation, relevant identifiers, and the error detail). Catching an exception to handle, recover from, or broadcast it MUST NOT swallow it: a caught failure MUST still record a log entry at an appropriate level (e.g. `warning` for expected/handled failures, `error` for crashes). Background jobs, queued work, and external-service calls — where failures are invisible to the user's request cycle — MUST log their outcomes.

### V. Performance with Clarity
Performance MUST be achieved without sacrificing readability or correctness. Optimizations MUST be measurable or justified; algorithmic efficiency, predictable resource use, and maintainable code paths are preferred over premature micro-optimization.

### VI. Consistent, Reusable Front-End Styling
Front-end styling MUST be reusable and centrally maintainable so a style change propagates from one place across the whole project.

- Styles MUST be expressed as simple, reusable classes/utilities with clear yet minimal names. Duplicating the same visual rule in multiple places is prohibited; recurring styling MUST be factored into a shared, well-named class or component.
- Colors MUST be referenced only through defined variables that name the color's ROLE — `primary`, `secondary`, `background`, `text`, and `accent` — never as raw or one-off hex/RGB literals at the point of use.
- Introducing a color value outside the defined palette variables is prohibited; changing the palette MUST be possible by editing the variable definitions alone, with no risk of stray off-palette variations remaining.

Rationale: role-named color variables and shared style classes make re-theming and visual edits a single-point change, eliminate drift, and keep the interface consistent and legible.

## Additional Constraints
- Code MUST follow repository style and linting rules, and formatting MUST be consistent.
- New behavior MUST include tests that prove correctness and guard against regressions.
- Documentation updates are required for non-trivial behavior, edge cases, and performance trade-offs.
- Duplicate logic MUST be eliminated in favor of shared, well-named abstractions.
- Side effects MUST be explicit, and state transitions MUST be predictable and easy to trace.

## Development Workflow
- Every change MUST be self-reviewed before submission and peer-reviewed before merge.
- Pull requests MUST explain how the work aligns with these principles, with notes on quality, simplicity, robustness, and performance decisions.
- Complexity MUST be justified in the PR description; if a simpler alternative existed and was rejected, the reason MUST be recorded.
- Tests and automation MUST be green before merge; blocked work is unacceptable.
- The team MUST use this constitution as the primary source for code quality decisions during design, review, and implementation.

## Governance
This constitution supersedes informal habits and local conventions for code quality, readability, simplicity, performance, and robustness. Amendments require a written rationale, review by a maintainer or owner, and an explicit update to the version metadata.

- Amendments that change, remove, or redefine principles MUST bump the major version.
- Amendments that add new principles, strengthen workflow requirements, or expand governance guidance MUST bump the minor version.
- Clarifications, wording improvements, and non-behavioral refinements MUST bump the patch version.
- Every pull request touching architecture, testing, or shared quality practices MUST reference at least one principle and note compliance in the description.
- Compliance reviews SHOULD occur whenever the project enters a new development phase or when a major feature lands.

**Version**: 1.3.0 | **Ratified**: 2026-06-02 | **Last Amended**: 2026-06-08

