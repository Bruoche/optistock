# Data Model: Mobile Scrollable Content Panel

**No data.** Presentation/layout only — no entities, fields, persisted state, request payloads, or API surface. No schema, migration, or serialization impact.

## Layout override set (not data — documented for traceability)

All overrides use Tailwind's `max-md:` variant (viewport < 768px). They are additive; the existing desktop classes are unchanged.

| Element | File | Current (desktop, kept) | Added (mobile) | Effect |
|---------|------|-------------------------|----------------|--------|
| Content panel | `pages/tour/optimize.tsx` | `overflow-hidden … p-4` | `max-md:overflow-y-auto max-md:p-0` | Panel scrolls as one; no framing padding |
| Result wrapper | `components/tour/result-summary.tsx` | `h-full` | `max-md:h-auto` | Content is natural height → panel scrolls it |
| Result bar | `components/tour/result-summary.tsx` | `rounded-md` | `max-md:rounded-none` | Flush edge-to-edge bar |
| Driver list | `components/tour/driver-list.tsx` | `flex-1 … overflow-y-auto` | `max-md:flex-none` | List is natural height (no inner scroll) |
| Editing wrapper | `components/tour/stop-list.tsx` | `h-full` | `max-md:h-auto` | Same as result wrapper |
| Stop list | `components/tour/stop-list.tsx` | `flex-1 … overflow-y-auto` | `max-md:flex-none` | Same as driver list |
| Control bar | `components/tour/tour-control-bar.tsx` | `rounded-md` | `max-md:rounded-none` | Flush edge-to-edge bar |

**Invariant**: at ≥ 768px none of these variants apply, so the desktop layout is byte-for-byte unchanged.
