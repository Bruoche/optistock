<?php

namespace App\Services;

use App\DTOs\DirectoryDriverData;
use App\Models\Driver;
use Illuminate\Support\Collection;

/** Reads the drivers matching the directory criteria and shapes them into directory rows (027). */
class DriverDirectoryService
{
    /**
     * @param  array<int, string>  $modes
     * @return Collection<int, array<string, mixed>>
     */
    public function search(?string $name, array $modes, ?int $warehouseId): Collection
    {
        return Driver::matching($name, $modes, $warehouseId)
            ->get()
            ->map(fn (Driver $driver): array => (new DirectoryDriverData($driver))->toArray())
            ->values();
    }
}
