<!--
Version change: none → 1.0.0
Modified principles: initial constitution created
Added sections: Additional Constraints, Development Workflow
Removed sections: none
Templates requiring updates: .specify/templates/plan-template.md ✅ reviewed, .specify/templates/spec-template.md ✅ reviewed, .specify/templates/tasks-template.md ✅ reviewed
Follow-up TODOs: none
-->

# Optistock Constitution

## Core Principles

### I. Quality First
Every change MUST preserve and improve code quality. Code MUST be complete, consistent, and supported by automated validation; tests and review gates are required for behavior that affects correctness, maintainability, or system health.

### II. Readable by Default
Code MUST be easy to read and understand for any team member. Prefer clear names, explicit intent, simple control flow, and minimal cognitive load; avoid cleverness, hidden behavior, and dense one-liners.

### III. Simple & Transparent
Design MUST favor the simplest solution that solves the problem correctly. Complexity is only acceptable when it is necessary, explicitly justified, and documented. Each module, class, and function MUST have a single clear responsibility.

### IV. Robustness as Standard
Robust code MUST validate inputs, handle failure modes explicitly, and preserve invariants. Errors MUST be surfaced clearly and safely rather than hidden, and defensive checks MUST protect production behavior without sacrificing clarity.

### V. Performance with Clarity
Performance MUST be achieved without sacrificing readability or correctness. Optimizations MUST be measurable or justified; algorithmic efficiency, predictable resource use, and maintainable code paths are preferred over premature micro-optimization.

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

**Version**: 1.0.0 | **Ratified**: 2026-06-02 | **Last Amended**: 2026-06-02

