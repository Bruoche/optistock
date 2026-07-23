# Contract: Driver-page map & day-bar presentation

Presentation-only contract for the driver-management page. No network/data contract changes.

## Map — rendering

- **On load** (no selection): every leg (tour + connection) draws immediately from `geometry ?? path` — straight fallback first, then each is replaced in place by its road polyline as `useDayGeometry` resolves it. No user action required. No `beforeId` dependency.
- **Single line invariant**: each segment has exactly one `Layer` at any time (its straight fallback OR its polyline). The selected tour has NO separate `RouteLayer` line — its line is the `DayLayer` highlighted leg.
- **Z-order**: with no `beforeId` anchor, `DayLayer` draws non-highlighted legs first and highlighted legs last, so the selected tour + its bracketing connections are never painted over by dimmed neutral lines.
- **Numbered stop markers**: the selected tour's stops still render as numbered markers via `TourMap stops`, unchanged.
- **Opacity** (`line-opacity`):
  | State | Selected tour + its bracketing connections | Every other segment |
  |---|---|---|
  | No tour selected | — | **0.75** |
  | A tour selected | **1.0** | **0.5** |
- **Unchanged**: line colour role (`--route-neutral` neutral / `--primary` highlighted), width (4), dash (connections dotted, tours solid), the T-markers and warehouse marker.

## Day bar — layout

- **Position**: below the map region, above the tour-list region (page stack: identity → map → day bar → tour list).
- **Weekday label**: the current weekday name is a title label above the **date field only**, aligned on the bar's label row with the other labels (Total workday, Driven, Stops, Break, Tour order).
- **Day navigation**: previous-day arrow, date field, next-day arrow share the value row; the weekday label is not above the arrows.
- **Alignment**: all bar labels align on one row, all values/controls on the row beneath (consistent with the tour pages).
- **Responsive**: groups wrap on narrow screens; no horizontal overflow (320–2560 px).
- **Unchanged**: the workday figures' content, the day-navigation behaviour, and the "Tour order" Update / Force-save controls and their logic.

## Frozen

- Driver-day, geometry, driver-update, and tour-order endpoints/payloads and all their behaviour.
- `RouteLayer`, `TourMap`, `DayMarkers`, `TourList`, `TourRow`, the hooks, and all backend code.
