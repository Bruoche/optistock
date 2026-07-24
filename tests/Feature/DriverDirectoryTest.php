<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Feature 027: the drivers-directory endpoint filters by name / modes / warehouse and sorts by name. */
class DriverDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_all_drivers_name_sorted_case_insensitively_with_the_row_shape(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create(['name' => 'North Depot']);

        // Mixed case proves LOWER(name) ordering: binary sort would place "Zara" before "bruno".
        Driver::factory()->withModes(['walking', 'driving'])->create(['name' => 'Zara', 'warehouse_id' => $warehouse->id, 'image_path' => null]);
        Driver::factory()->withModes(['driving'])->create(['name' => 'bruno', 'warehouse_id' => $warehouse->id, 'image_path' => null]);

        $this->actingAs($user)
            ->getJson(route('api.drivers.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'bruno')
            ->assertJsonPath('data.1.name', 'Zara')
            ->assertJsonPath('data.0.image_url', null)
            ->assertJsonPath('data.0.modes', ['driving'])
            ->assertJsonPath('data.0.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.0.warehouse_name', 'North Depot');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.drivers.index'))->assertUnauthorized();
    }

    public function test_an_unknown_warehouse_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.drivers.index', ['warehouse' => 999999]))
            ->assertStatus(422);
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.drivers.index', ['modes' => ['flying']]))
            ->assertStatus(422);
    }

    public function test_the_name_criterion_matches_partially_and_case_insensitively(): void
    {
        $user = User::factory()->create();
        foreach (['Sacha Brook', 'Charline Klein', 'Hector Chard', 'Diego Ruiz'] as $name) {
            Driver::factory()->withModes(['driving'])->create(['name' => $name]);
        }

        $names = fn (array $criteria): array => collect(
            $this->actingAs($user)->getJson(route('api.drivers.index', $criteria))->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['Charline Klein', 'Hector Chard', 'Sacha Brook'], $names(['name' => 'cha']));
        $this->assertSame(['Charline Klein', 'Hector Chard', 'Sacha Brook'], $names(['name' => 'CHA']));
        $this->assertCount(4, $names(['name' => '   ']));
    }

    public function test_selected_modes_are_all_required(): void
    {
        $user = User::factory()->create();
        $both = Driver::factory()->withModes(['trucking', 'driving'])->create(['name' => 'Both']);
        $truckOnly = Driver::factory()->withModes(['trucking'])->create(['name' => 'TruckOnly']);
        Driver::factory()->withModes(['driving'])->create(['name' => 'DriveOnly']);

        $this->actingAs($user)
            ->getJson(route('api.drivers.index', ['modes' => ['trucking']]))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response = $this->actingAs($user)
            ->getJson(route('api.drivers.index', ['modes' => ['trucking', 'driving']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Both');

        $this->assertNotNull($both);
        $this->assertNotNull($truckOnly);
        $this->assertNotNull($response);
    }

    public function test_the_warehouse_criterion_restricts_to_that_warehouse(): void
    {
        $user = User::factory()->create();
        $north = Warehouse::factory()->create();
        $south = Warehouse::factory()->create();
        Driver::factory()->withModes(['driving'])->create(['name' => 'Northerner', 'warehouse_id' => $north->id]);
        Driver::factory()->withModes(['driving'])->create(['name' => 'Southerner', 'warehouse_id' => $south->id]);

        $this->actingAs($user)
            ->getJson(route('api.drivers.index', ['warehouse' => $north->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Northerner');
    }

    public function test_the_criteria_combine_conjunctively(): void
    {
        $user = User::factory()->create();
        $depot = Warehouse::factory()->create();
        $wanted = Driver::factory()->withModes(['trucking', 'driving'])->create(['name' => 'Sacha Brook', 'warehouse_id' => $depot->id]);
        // Shares the name fragment but lacks the mode; shares the mode but wrong warehouse.
        Driver::factory()->withModes(['driving'])->create(['name' => 'Sacha Other', 'warehouse_id' => $depot->id]);
        Driver::factory()->withModes(['trucking', 'driving'])->create(['name' => 'Sacha Elsewhere', 'warehouse_id' => Warehouse::factory()->create()->id]);

        $this->actingAs($user)
            ->getJson(route('api.drivers.index', [
                'name' => 'sacha',
                'modes' => ['trucking'],
                'warehouse' => $depot->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }
}
