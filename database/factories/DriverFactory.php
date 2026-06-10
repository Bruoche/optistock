<?php

namespace Database\Factories;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
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
        ];
    }

    /**
     * Every factory driver gets a random valid mode set (1–3); withModes() overrides it.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Driver $driver) => $driver->deliveryModes()->sync(
            $this->modeIds($this->randomModeLabels()),
        ));
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
}
