<?php

namespace Database\Factories;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

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
     * Attach a random valid mode set (1–3, at least one) unless a state already
     * set specific modes.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Driver $driver) {
            if ($driver->deliveryModes()->exists()) {
                return;
            }

            $labels = collect(DeliveryModeEnum::cases())
                ->map(fn (DeliveryModeEnum $mode): string => $mode->value)
                ->random(fake()->numberBetween(1, 3));

            $driver->deliveryModes()->sync($this->modeIds($labels));
        });
    }

    /**
     * Force an exact set of supported modes (deterministic for tests).
     *
     * @param  array<int, string>  $labels
     */
    public function withModes(array $labels): static
    {
        return $this->afterCreating(function (Driver $driver) use ($labels): void {
            $driver->deliveryModes()->sync($this->modeIds(collect($labels)));
        });
    }

    /**
     * Resolve mode labels to their lookup-row ids, creating any missing rows.
     *
     * @param  Collection<int, string>  $labels
     * @return array<int, int>
     */
    private function modeIds($labels): array
    {
        return $labels
            ->map(fn (string $label): int => DeliveryMode::firstOrCreate(['label' => $label])->id)
            ->all();
    }
}
