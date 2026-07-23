<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Feature 025 (US3): editing a driver's details; validation; assignments untouched. */
class UpdateDriverTest extends TestCase
{
    use RefreshDatabase;

    private function patchDriver(User $user, Driver $driver, array $payload)
    {
        // Multipart method spoofing (POST + _method=PATCH), as the frontend sends it.
        return $this->actingAs($user)->post(
            route('api.driver.update', ['driver' => $driver->id]),
            ['_method' => 'PATCH', ...$payload],
        );
    }

    public function test_it_saves_name_modes_and_warehouse(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create(['name' => 'Old']);
        $warehouse = Warehouse::factory()->create(['name' => 'South Depot']);

        $this->patchDriver($user, $driver, [
            'name' => 'New Name',
            'warehouse_id' => $warehouse->id,
            'modes' => ['driving', 'trucking'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.warehouse_name', 'South Depot');

        $driver->refresh();
        $this->assertSame('New Name', $driver->name);
        $this->assertSame($warehouse->id, $driver->warehouse_id);
        $this->assertEqualsCanonicalizing(
            ['driving', 'trucking'],
            $driver->deliveryModes->pluck('label')->all(),
        );
    }

    public function test_it_stores_an_uploaded_image_on_the_public_disk(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create(['image_path' => null]);

        $this->patchDriver($user, $driver, [
            'name' => 'Photo Phil',
            'warehouse_id' => $driver->warehouse_id,
            'modes' => ['driving'],
            'image' => UploadedFile::fake()->create('phil.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        $driver->refresh();
        $this->assertNotNull($driver->image_path);
        Storage::disk('public')->assertExists($driver->image_path);
    }

    public function test_an_empty_name_is_rejected_and_nothing_changes(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create(['name' => 'Keep']);

        $this->patchDriver($user, $driver, [
            'name' => '',
            'warehouse_id' => $driver->warehouse_id,
            'modes' => ['driving'],
        ])->assertSessionHasErrors('name');

        $this->assertSame('Keep', $driver->fresh()->name);
    }

    public function test_zero_modes_is_rejected(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();

        $this->patchDriver($user, $driver, [
            'name' => 'No Modes',
            'warehouse_id' => $driver->warehouse_id,
            'modes' => [],
        ])->assertSessionHasErrors('modes');
    }

    public function test_existing_assignments_are_left_untouched(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();
        $warehouse = Warehouse::factory()->create();

        $tour = Tour::factory()->withMode('driving')->create(['user_id' => $user->id, 'loop' => true]);
        Stop::factory()->for($tour)->create(['position' => 0]);
        Stop::factory()->for($tour)->create(['position' => 1]);
        $tour->drivers()->attach($driver, [
            'date' => '2026-07-06',
            'start_latitude' => 48.85, 'start_longitude' => 2.35,
            'end_latitude' => 48.86, 'end_longitude' => 2.36,
            'sequence' => 0,
        ]);

        $this->patchDriver($user, $driver, [
            'name' => 'Moved',
            'warehouse_id' => $warehouse->id,
            'modes' => ['walking'], // driver no longer runs the assigned tour's mode — allowed
        ])->assertOk();

        $this->assertDatabaseHas('driver_tour', ['tour_id' => $tour->id, 'driver_id' => $driver->id]);
    }

    public function test_an_unknown_driver_is_not_found(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->actingAs($user)->post(route('api.driver.update', ['driver' => 999999]), [
            '_method' => 'PATCH',
            'name' => 'X',
            'warehouse_id' => $warehouse->id,
            'modes' => ['driving'],
        ])->assertNotFound();
    }
}
