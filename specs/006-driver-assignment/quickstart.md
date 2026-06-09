# Quickstart: Delivery Driver Assignment

## Setup

```bash
php artisan migrate          # creates drivers, delivery_modes, driver_delivery_mode
php artisan db:seed --class=DeliveryModeSeeder   # 3 mode rows (idempotent)
```

Seed a few demo drivers (tinker or a demo seeder) with mixed mode sets, e.g.:
- "Amélie Durand" → driving, walking
- "Bruno Klein" → driving
- "Carla Mensah" → trucking
- "Dimitri Roux" → walking

At least one driver with no image (to see the placeholder).

## Manual verification

1. `php artisan serve` + `npm run dev`; log in; open `/tour`.
2. Add ≥2 stops, pick a **mode**, Optimize.
3. When the result shows, the stop-list region now holds the **driver list**:
   - only drivers whose modes include the chosen mode appear;
   - each shows name (prominent) + the correct mode icons (person / car / truck);
   - a driver with no image shows the placeholder icon.
4. **Driving**: Amélie + Bruno appear (alphabetical). **Trucking**: only Carla. **Walking**: Amélie + Dimitri.
5. Optimize a tour with a mode no driver supports (temporarily) → the message
   **"No one available for this delivery."** shows in place of the list.
6. "New tour" → optimize again with a different mode → the list refreshes to the new mode's drivers.

## API spot-check

```bash
# authenticated session cookie required
curl -s 'http://localhost:8000/api/tour/drivers?mode=driving' -H 'Accept: application/json'
# → { "data": [ { id, name, image_url, modes:[...] }, ... ] }  ordered by name

curl -s 'http://localhost:8000/api/tour/drivers?mode=bogus' -H 'Accept: application/json'
# → 422
```

## Tests

```bash
php artisan test --filter=DriverAvailabilityTest    # endpoint: auth, filtering, order, shape, 422, empty
php artisan test --filter=DriverTest                # model: available scope, image_url accessor
npm run test -- driver-list                         # list: names, icons, empty message, order, placeholder
```
