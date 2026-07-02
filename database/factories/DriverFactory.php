<?php

namespace Database\Factories;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
use App\Models\Warehouse;
use App\Models\WeekDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'image_path' => fake()->boolean(70) ? 'drivers/'.fake()->uuid().'.jpg' : null,
            'warehouse_id' => Warehouse::factory(),
        ];
    }

    /**
     * Every factory driver gets a random valid mode set (1–3) and a random
     * non-empty schedule (1–7 weekdays); withModes()/withDays() override each.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Driver $driver): void {
            $driver->deliveryModes()->sync($this->modeIds($this->randomModeLabels()));
            $driver->weekDays()->sync($this->dayIds($this->randomDayLabels()));
        });
    }

    /**
     * Force an exact set of supported modes (deterministic for tests).
     *
     * @param  array<int, string>  $labels
     */
    public function withModes(array $labels): static
    {
        return $this->afterCreating(
            fn (Driver $driver) => $driver->deliveryModes()->sync($this->modeIds($labels)),
        );
    }

    /**
     * Force an exact weekday schedule (deterministic for tests).
     *
     * @param  array<int, string>  $labels
     */
    public function withDays(array $labels): static
    {
        return $this->afterCreating(
            fn (Driver $driver) => $driver->weekDays()->sync($this->dayIds($labels)),
        );
    }

    /**
     * @return array<int, string>
     */
    private function randomModeLabels(): array
    {
        return collect(DeliveryModeEnum::cases())
            ->map(fn (DeliveryModeEnum $mode): string => $mode->value)
            ->random(fake()->numberBetween(1, 3))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function randomDayLabels(): array
    {
        return collect(WeekDayEnum::cases())
            ->map(fn (WeekDayEnum $day): string => $day->value)
            ->random(fake()->numberBetween(1, 7))
            ->all();
    }

    /**
     * Resolve mode labels to their lookup-row ids, creating any missing rows.
     *
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    private function modeIds(array $labels): array
    {
        return collect($labels)
            ->map(fn (string $label): int => DeliveryMode::firstOrCreate(['label' => $label])->id)
            ->all();
    }

    /**
     * Resolve weekday labels to their lookup-row ids, creating any missing rows.
     *
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    private function dayIds(array $labels): array
    {
        return collect($labels)
            ->map(fn (string $label): int => WeekDay::firstOrCreate(['label' => $label])->id)
            ->all();
    }
}
