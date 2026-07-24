<?php

namespace App\DTOs;

use App\Models\Driver;

/**
 * One row of the drivers directory (feature 027): the driver's identity as the list shows it —
 * no workday, road, or break figures (those belong to the tour-assignment context).
 */
final class DirectoryDriverData
{
    public function __construct(private readonly Driver $driver) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->driver->id,
            'name' => $this->driver->name,
            'image_url' => $this->driver->image_url,
            'modes' => $this->driver->deliveryModes->pluck('label')->all(),
            'warehouse_id' => $this->driver->warehouse->id,
            'warehouse_name' => $this->driver->warehouse->name,
        ];
    }
}
