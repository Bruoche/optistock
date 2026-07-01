<?php

namespace Tests\Unit;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
use Database\Seeders\DeliveryModeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_scope_filters_by_mode_and_weekday_ordered_by_name(): void
    {
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Bravo']);
        Driver::factory()->withModes(['walking'])->withDays(['monday'])->create(['name' => 'Zulu']);     // wrong mode
        Driver::factory()->withModes(['driving'])->withDays(['tuesday'])->create(['name' => 'Charlie']); // wrong day
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Alpha']);

        $names = Driver::available(DeliveryModeEnum::Driving, WeekDayEnum::Monday)
            ->get()->pluck('name')->all();

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
