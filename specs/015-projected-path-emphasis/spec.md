# Feature Specification: Projected Path Emphasis

**Feature Branch**: `015-projected-path-emphasis`

**Created**: 2026-07-03

**Status**: Draft

**Input**: User description: "We are going to upgrade the presentation menu. The doted paths to and from the projected optimized tour will now be orange too, so we can distinguish them from pre-existing doted connexion paths (and clearly show if the projected path adds a lot of travel inbetween tours/warehouse). Also, we are going to put black lines at 50% opacity, so they don't look as highlighted and it's clear the primary orange paths are the important one."

## Context

Feature 014 made a driver's projected workday visible on the presentation map:
clicking a driver draws their whole day — the connection drives from the
warehouse to their first tour, each already-assigned tour, the connection drives
between tours, the candidate tour slotted into the chain, and the return to the
warehouse. The candidate tour is drawn in the primary color (orange); every
other segment — prior tours and all connection drives — is drawn in a single
neutral color (near-black), with connection drives dotted and tours solid.

That neutral-everything treatment makes the added workload hard to weigh: the
dotted drives that stitch the candidate tour *into* the driver's day look
identical to the pre-existing dotted drives that already connected their assigned
tours and the warehouse. The manager cannot tell at a glance how much extra
driving the candidate tour injects.

This feature sharpens the preview into two emphasis tiers. The two connection
drives that attach the candidate tour to the rest of the day — the drive **into**
its start and the drive **out of** its end — are recolored to the same primary
color (orange) as the candidate tour, so the freshly projected path (tour plus
its connecting drives) reads as one continuous orange commitment, and any large
detour it adds between the existing tours or the warehouse is immediately
obvious. Everything that was already part of the driver's day — their prior tours
and the connection drives among them and the warehouse — is dimmed to half
opacity, so it recedes into context and the primary orange path stands out as
the thing being decided.

Nothing about the chain itself, the assignment flow, or the progressive
straight-line-then-road-path rendering changes — this is purely how the existing
segments are colored and weighted.

## Clarifications

### Session 2026-07-03

- Q: Which palette color are the candidate tour's connecting drives drawn in? → A: The palette's **primary** color (primary orange) — the exact same role the candidate tour itself uses; there is no separate "highlight" color. Throughout this spec, "primary color" means that shared primary palette role.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the candidate tour's connecting drives in the primary color (Priority: P1)

In the preview, the two connection drives that link the candidate tour to the
rest of the driver's day — the drive from the previous point in the chain into
the candidate tour's start, and the drive from the candidate tour's end onward —
are drawn in the same primary color (orange) as the candidate tour itself,
rather than in the neutral color. All other connection drives (warehouse to first
tour, between prior tours) stay neutral and dotted. This lets the manager see the
whole projected path — the candidate tour and the driving it requires to reach
and leave it — as one distinct orange shape, and judge how much extra travel it
adds between the existing tours and the warehouse.

**Why this priority**: This is the feature's core value — separating the drives
the candidate tour *adds* from the drives that already existed is exactly what
lets the manager weigh the new commitment.

**Independent Test**: Show a preview for a driver with at least one prior tour and
verify the connection drive entering the candidate tour's start and the one
leaving its end are drawn in the primary color, while the warehouse and
between-prior-tour connection drives remain neutral — all still dotted.

**Acceptance Scenarios**:

1. **Given** a preview is displayed, **When** the manager looks at the drive leading into the candidate tour and the drive leaving it, **Then** both are drawn in the same primary color as the candidate tour, and both remain dotted.
2. **Given** a preview with at least one prior tour, **When** the manager looks at the warehouse-to-first-tour drive and any between-prior-tour drives, **Then** they remain in the neutral color and dotted, visibly distinct from the primary-colored candidate connections.
3. **Given** a driver with no prior tours for the date, **When** their preview is shown, **Then** both connection drives (warehouse to candidate, candidate to warehouse) are candidate-adjacent and are drawn in the primary color.
4. **Given** a candidate connection drive is first shown as a straight-line placeholder, **When** its road-accurate geometry arrives and replaces it, **Then** it stays the primary color throughout — the upgrade does not change its color.

---

### User Story 2 - Recede the pre-existing paths at half opacity (Priority: P2)

Everything in the preview that is not part of the candidate emphasis set — the
driver's prior tours and the neutral connection drives around them and the
warehouse — is drawn at 50% opacity, so it fades into the background. The
candidate tour and its two connecting drives stay at full opacity. The result is
that the primary orange path is unmistakably the important one and the already-
planned day sits behind it as context.

**Why this priority**: The re-coloring in Story 1 is what conveys the meaning;
the dimming makes it pop. The preview is legible with Story 1 alone, but the
opacity contrast is what makes the orange path read as *primary* at a glance.

**Independent Test**: Show a preview with at least one prior tour and verify the
prior tour lines and the neutral connection drives render at reduced (half)
opacity while the candidate tour and its two connecting drives render fully
opaque.

**Acceptance Scenarios**:

1. **Given** a preview with at least one prior tour, **When** the manager looks at the prior tour paths and the neutral connection drives, **Then** they are drawn at 50% opacity.
2. **Given** the same preview, **When** the manager looks at the candidate tour and its two connecting drives, **Then** they are drawn at full opacity, clearly more prominent than the dimmed segments.
3. **Given** a neutral segment shown as a straight-line placeholder, **When** its road-accurate geometry arrives, **Then** it remains at 50% opacity — the upgrade does not change its opacity.

---

### Edge Cases

- **Driver with no prior tours for the date**: the whole projected path is the candidate emphasis set — the two connection drives (both candidate-adjacent) are orange and full opacity, the candidate tour is orange; there are no neutral segments to dim.
- **Re-clicking the selected driver / date change / list refresh**: the preview clears back to the candidate tour only (feature 014 behavior); the emphasis rules apply only while a driver's workday is previewed.
- **A candidate connection drive whose geometry fails to fetch**: its straight-line depiction stays, still in the primary color at full opacity (feature 014 keeps the straight line; only its color/opacity are governed here).
- **Straight-line placeholder vs road-accurate path**: color and opacity are determined by the segment's role, not its geometry state — a segment must not change color or opacity when its straight line upgrades to a road path.
- **Rapid driver cycling**: unchanged from feature 014 — each segment's emphasis is a static property of its role in the shown chain, independent of which geometry has arrived.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: In the preview, the connection drive entering the candidate tour's start and the connection drive leaving the candidate tour's end MUST be drawn in the same primary color as the candidate tour, instead of the neutral color.
- **FR-002**: All other connection drives (warehouse to first tour, between prior tours) MUST remain in the neutral color, keeping them visibly distinct from the primary-colored candidate connections.
- **FR-003**: The candidate connection drives MUST keep their dotted line style — only their color changes; connections stay dotted and tours stay solid as in feature 014.
- **FR-004**: When the driver has no prior tours for the date, both connection drives are candidate-adjacent and MUST both be drawn in the primary color.
- **FR-005**: Every segment not in the candidate emphasis set — the driver's prior tours and all neutral connection drives — MUST be drawn at 50% opacity.
- **FR-006**: The candidate tour and its two connecting drives (the candidate emphasis set) MUST be drawn at full opacity.
- **FR-007**: A segment's color and opacity MUST be determined by its role in the chain, not by its geometry state: a straight-line placeholder and its road-accurate replacement MUST render with the same color and opacity.
- **FR-008**: The primary color and the neutral color MUST continue to be referenced through the project's role-named palette, with no raw color literal introduced at the point of use (carrying feature 014's styling rule).

### Key Entities *(include if feature involves data)*

- **Candidate emphasis set**: the candidate tour together with the two connection drives immediately bracketing it in the chain (the drive into its start and the drive out of its end); rendered in the primary color at full opacity.
- **Path segment emphasis**: a rendering property of each previewed segment derived solely from its role — candidate tour, a candidate-adjacent connection drive, a prior tour, or a non-candidate connection drive — determining its color (primary vs neutral) and opacity (full vs 50%), independent of whether the segment is currently a straight-line placeholder or a road-accurate path.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In any preview with at least one prior tour, a viewer can identify the driving the candidate tour adds (into and out of it) by color alone, distinct from the pre-existing connection drives, in 100% of previews.
- **SC-002**: In any preview with at least one prior tour, the candidate tour and its two connecting drives are the most visually prominent element, with all other segments visibly receded at half opacity, in 100% of previews.
- **SC-003**: A previewed segment's color and opacity never change when its straight-line placeholder upgrades to a road-accurate path, in 100% of upgrades.
- **SC-004**: No regression to feature 014: the previewed chain, the assignment flow, and rapid-cycling correctness behave exactly as before, in 100% of cases.

## Assumptions

- **Builds on 014**: the projected-workday preview, its chain order (prior tours, then the candidate tour appended, bracketed by connection drives to/from the warehouse), the straight-line-then-road-path rendering, and the driver-cycling correctness are all as delivered in feature 014; this feature only re-colors and re-weights the already-drawn segments.
- **"Orange" is the palette's primary role**: the description's "orange" is the palette's **primary** color role — the exact color the candidate tour already uses on the presentation map, referenced through the role-named palette, not a new literal color and not a separate "highlight" role. "Black lines" are the current neutral-colored segments (near-black neutral role).
- **"Paths to and from the projected optimized tour"**: interpreted as exactly the two connection drives immediately bracketing the candidate tour in the chain — the drive into its start stop and the drive out of its end stop. The projected optimized tour is the candidate tour being previewed for assignment.
- **Scope of the 50% dimming**: all non-candidate segments dim — both the solid prior-tour lines and the dotted non-candidate connection drives — since the goal is that only the primary orange path (candidate tour plus its connecting drives) reads as important.
- **Candidate tour styling unchanged**: the candidate tour keeps its existing primary color and full opacity; this feature adds no change to how it is drawn, only aligns its two connecting drives to it and dims the rest.
- **Half opacity is a fixed value**: "50% opacity" is taken literally as the dim level; no per-segment or configurable opacity is introduced.
- **Presentation-only**: this is a rendering change; it records, modifies, and persists nothing, and does not touch the chain computation or the assignment write path.
