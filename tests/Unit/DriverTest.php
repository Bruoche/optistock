<?php

namespace Tests\Unit;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
use Database\Seeders\DeliveryModeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_scope_filters_and_orders_by_name(): void
    {
        Driver::factory()->withModes(['driving'])->create(['name' => 'Bravo']);
        Driver::factory()->withModes(['walking'])->create(['name' => 'Zulu']);
        Driver::factory()->withModes(['driving'])->create(['name' => 'Alpha']);

        $names = Driver::available(DeliveryModeEnum::Driving)->get()->pluck('name')->all();

        $this->assertSame(['Alpha', 'Bravo'], $names);
    }

    public function test_image_url_accessor_handles_path_and_null(): void
    {
        $withImage = Driver::factory()->withModes(['driving'])->create(['image_path' => 'drivers/x.jpg']);
        $withoutImage = Driver::factory()->withModes(['driving'])->create(['image_path' => null]);

        $this->assertStringContainsString('drivers/x.jpg', $withImage->image_url);
        $this->assertNull($withoutImage->image_url);
    }

    public function test_seeded_labels_match_the_enum(): void
    {
        $this->seed(DeliveryModeSeeder::class);

        $labels = DeliveryMode::query()->pluck('label')->sort()->values()->all();
        $expected = collect(DeliveryModeEnum::cases())
            ->map(fn (DeliveryModeEnum $mode): string => $mode->value)
            ->sort()->values()->all();

        $this->assertSame($expected, $labels);
    }
}
