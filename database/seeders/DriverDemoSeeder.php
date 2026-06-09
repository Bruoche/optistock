<?php

namespace Database\Seeders;

use App\Models\DeliveryMode;
use App\Models\Driver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Spoof drivers for manual smoke tests — a mix of mode sets (covering every mode
 * and multi-mode drivers) and a mix of avatar / no-avatar so the results-page
 * list, mode icons, image placeholder, and the empty case can all be exercised.
 *
 * Idempotent (keyed by name) — safe to re-run.
 */
class DriverDemoSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, image_path: string|null, modes: array<int, string>}>
     */
    private const DRIVERS = [
        ['name' => 'Amélie Durand', 'image_path' => 'drivers/amelie.svg', 'modes' => ['driving', 'walking']],
        ['name' => 'Bruno Klein', 'image_path' => null, 'modes' => ['driving']],
        ['name' => 'Carla Mensah', 'image_path' => 'drivers/carla.svg', 'modes' => ['trucking']],
        ['name' => 'Dimitri Roux', 'image_path' => null, 'modes' => ['walking']],
        ['name' => 'Esra Yılmaz', 'image_path' => 'drivers/esra.svg', 'modes' => ['trucking', 'driving', 'walking']],
        ['name' => 'Farid Benali', 'image_path' => 'drivers/farid.svg', 'modes' => ['trucking', 'driving']],
        ['name' => 'Hana Park', 'image_path' => null, 'modes' => ['driving', 'walking']],
        ['name' => 'Igor Petrov', 'image_path' => 'drivers/igor.svg', 'modes' => ['trucking']],
    ];

    public function run(): void
    {
        foreach (self::DRIVERS as $row) {
            $driver = Driver::updateOrCreate(
                ['name' => $row['name']],
                ['image_path' => $row['image_path']],
            );

            $driver->deliveryModes()->sync($this->modeIds($row['modes']));
        }
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    private function modeIds(array $labels): array
    {
        return Collection::make($labels)
            ->map(fn (string $label): int => DeliveryMode::firstOrCreate(['label' => $label])->id)
            ->all();
    }
}
