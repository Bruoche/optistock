# Quickstart: Drivers Directory (feature 027)

## What ships

A `/drivers` page listing every driver with a three-criterion filter bar, backed by one new,
fully-additive endpoint `GET /api/drivers`. No existing endpoint, payload, or behavior changes.

## Backend (additive only)

1. **Route** — `routes/api.php`, inside the existing `auth` group:
   ```php
   Route::get('drivers', [DriverDirectoryController::class, 'index'])
       ->middleware('throttle:tour-read')
       ->name('drivers.index');
   ```
   **Web** — `routes/web.php`, inside the `auth`+`verified` group (singular `driver`, coherent with
   the existing routes; distinct from `driver/{driver}`):
   ```php
   Route::get('driver', [DriverPageController::class, 'directory'])->name('driver.directory.page');
   ```
2. **`DriverDirectoryRequest`** — validates `name` (`nullable string max:255`), `modes`
   (`nullable array`, `modes.*` `Rule::enum(DeliveryMode)`), `warehouse`
   (`nullable integer exists:warehouses,id`). `authorize()` → user is authenticated.
3. **`Driver::scopeMatching(name, modes, warehouseId)`** — beside `scopeAvailable`; adds the
   `LOWER(name) LIKE`, per-mode `whereHas` (AND), and `warehouse_id` clauses only when present;
   eager-loads `deliveryModes`+`warehouse`, `orderByRaw('LOWER(name)')` (case-insensitive sort).
4. **`DriverDirectoryService::search(?name, modes[], ?warehouseId): Collection`** — runs the scope,
   maps each `Driver` to a `DirectoryDriverData`.
5. **`DirectoryDriverData`** — DTO with `toArray()` → `{ id, name, image_url, modes, warehouse_id,
   warehouse_name }`.
6. **`DriverDirectoryController::index(DriverDirectoryRequest, DriverDirectoryService)`** — thin:
   `return response()->json(['data' => $service->search(...)->map->toArray()])`.
7. **`DriverPageController::directory`** — new method returning
   `Inertia::render('driver/directory', ['warehouses' => Warehouse::orderBy('name')->get(['id','name'])])`.
   `manage()` stays byte-for-byte.

## Frontend

8. **`types/driver.ts`** — add `DirectoryDriver` (see data-model.md); reuse `WarehouseOption`.
9. **`hooks/use-drivers-directory.ts`** — takes `{ name, modes, warehouseId }`; builds the query
   string, `fetch('/api/drivers?…')`; debounces `name` (~200ms), cancels the prior request, and only
   commits a response whose criteria still equal the current ones (settle-on-latest). Returns
   `{ drivers, status }` with `loading | ready | error`.
10. **`components/driver/driver-summary.tsx`** — the shared identity block (avatar/placeholder +
    name + mode icons + warehouse line) lifted verbatim from `driver-list.tsx`.
11. **`components/tour/driver-list.tsx`** — render `<DriverSummary>` then the existing figures
    (behavior-preserving refactor).
12. **`components/driver/directory-bar.tsx`** — name `<input>`, a mode multi-toggle (icons/labels
    from `DELIVERY_MODES`/`MODE_ICON`), and a warehouse `<Select>` with an "Any warehouse" option;
    `flex-wrap`, role-named palette, no horizontal overflow. Emits criteria changes to the page.
13. **`pages/driver/directory.tsx`** — holds criteria state, renders `<DirectoryBar>` above the list;
    list uses the hook: spinner while `loading`, error message on `error`, the exact text
    `no drivers found with current criterias.` when `ready` with zero rows, otherwise the rows.
    Each row is a link to `/driver/{id}`.

## Verify

```bash
php artisan test --filter=DriverDirectory     # name / modes-AND / warehouse / combined / empty / 422
npm run test -- use-drivers-directory directory-bar driver-summary
npm run format:check && npm run lint && npm run types
```

Manual: seed drivers `Sacha Brook`, `Charline Klein`, `Hector Chard`, `Diego Ruiz`; open `/driver`
→ all four, name-sorted. Type `cha` → first three. Select two modes → only drivers with both. Add a
warehouse → narrows further. Clear all → four again. A no-match combo → the exact empty-state text.
Click a row → its `/driver/{id}` management page. Resize to 320px → no horizontal overflow.

## Guardrails

- **New endpoint only.** `GET /api/tour/drivers`, `/api/driver/{driver}/day`, update, tour-order,
  optimize/status/force/geometry/assign — all frozen and untouched.
- No migration, no new dependency, no schema change.
- Role-named palette only (FR-016); no one-off colours.
- Never a silent empty list — loading / error / no-match are distinct states.
