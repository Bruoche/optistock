<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDriverRequest;
use App\Models\DeliveryMode;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Saves an administrator's edits to a driver's details (feature 025): name, picture,
 * supported delivery modes, and assigned warehouse. Existing tour assignments are left
 * untouched — they recompute from the new details when their day is next viewed (FR-007b).
 */
class DriverUpdateController extends Controller
{
    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        if ($request->hasFile('image')) {
            $previousPath = $driver->image_path;
            $driver->image_path = $request->file('image')->store('drivers', 'public');

            if ($previousPath !== null) {
                Storage::disk('public')->delete($previousPath);
            }
        }

        $driver->name = $request->validated('name');
        $driver->warehouse_id = $request->integer('warehouse_id');
        $driver->save();

        // The mode labels are validated against the enum; ensure their lookup rows exist
        // (they are seeded in production) so a sync never silently drops a valid mode.
        $modeIds = collect($request->validated('modes'))
            ->map(fn (string $label): int => DeliveryMode::firstOrCreate(['label' => $label])->id);
        $driver->deliveryModes()->sync($modeIds);

        $driver->load('deliveryModes', 'warehouse');

        return response()->json([
            'data' => [
                'id' => $driver->id,
                'name' => $driver->name,
                'image_url' => $driver->image_url,
                'modes' => $driver->deliveryModes->pluck('label')->all(),
                'warehouse_id' => $driver->warehouse->id,
                'warehouse_name' => $driver->warehouse->name,
                'warehouse_coordinate' => [$driver->warehouse->latitude, $driver->warehouse->longitude],
            ],
        ]);
    }
}
