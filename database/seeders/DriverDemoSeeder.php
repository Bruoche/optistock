<?php

namespace Database\Seeders;

use App\Models\DeliveryMode;
use App\Models\Driver;
use App\Models\WeekDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Spoof drivers for manual smoke tests — a mix of mode sets (covering every mode
 * and multi-mode drivers), a mix of avatar / no-avatar, and a mix of weekly
 * schedules (Mon–Fri, weekend-only, a 4-day week, all-week) so the results-page
 * list, mode icons, image placeholder, date filtering, and the empty case can all
 * be exercised.
 *
 * Idempotent (keyed by name) — safe to re-run.
 */
class DriverDemoSeeder extends Seeder
{
    private const WORKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    private const WEEKEND = ['saturday', 'sunday'];

    private const FOUR_DAY = ['monday', 'tuesday', 'wednesday', 'thursday'];

    private const ALL_WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @var array<int, array{name: string, image_path: string|null, modes: array<int, string>, days: array<int, string>}>
     */
    private const DRIVERS = [
        ['name' => 'Amélie Durand', 'image_path' => 'drivers/amelie.svg', 'modes' => ['driving', 'walking'], 'days' => self::WORKDAYS],
        ['name' => 'Bruno Klein', 'image_path' => null, 'modes' => ['driving'], 'days' => self::WEEKEND],
        ['name' => 'Carla Mensah', 'image_path' => 'drivers/carla.svg', 'modes' => ['trucking'], 'days' => self::ALL_WEEK],
        ['name' => 'Dimitri Roux', 'image_path' => null, 'modes' => ['walking'], 'days' => self::FOUR_DAY],
        ['name' => 'Esra Yılmaz', 'image_path' => 'drivers/esra.svg', 'modes' => ['trucking', 'driving', 'walking'], 'days' => self::WORKDAYS],
        ['name' => 'Farid Benali', 'image_path' => 'drivers/farid.svg', 'modes' => ['trucking', 'driving'], 'days' => self::WEEKEND],
        ['name' => 'Hana Park', 'image_path' => null, 'modes' => ['driving', 'walking'], 'days' => self::ALL_WEEK],
        ['name' => 'Igor Petrov', 'image_path' => 'drivers/igor.svg', 'modes' => ['trucking'], 'days' => self::FOUR_DAY],
    ];

    public function run(): void
    {
        foreach (self::DRIVERS as $row) {
            $driver = Driver::updateOrCreate(
                ['name' => $row['name']],
                ['image_path' => $row['image_path']],
            );

            $driver->deliveryModes()->sync($this->modeIds($row['modes']));
            $driver->weekDays()->sync($this->dayIds($row['days']));
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

    /**
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    private function dayIds(array $labels): array
    {
        return Collection::make($labels)
            ->map(fn (string $label): int => WeekDay::firstOrCreate(['label' => $label])->id)
            ->all();
    }
}
