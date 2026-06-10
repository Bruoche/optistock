<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(route('api.tour.drivers', ['mode' => 'driving']))
            ->assertUnauthorized();
    }

    public function test_it_returns_only_drivers_supporting_the_mode_alphabetically(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving', 'walking'])->create(['name' => 'Bruno']);
        Driver::factory()->withModes(['walking'])->create(['name' => 'Carla']);
        Driver::factory()->withModes(['driving'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'driving']));

        $response->assertOk();
        $this->assertSame(['Amelie', 'Bruno'], array_column($response->json('data'), 'name'));
    }

    public function test_it_exposes_image_url_and_modes_shape(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving'])->create(['name' => 'Amelie', 'image_path' => 'drivers/a.jpg']);
        Driver::factory()->withModes(['driving'])->create(['name' => 'Bruno', 'image_path' => null]);

        $response = $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'driving']));

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Amelie')
            ->assertJsonPath('data.0.modes', ['driving'])
            ->assertJsonPath('data.1.image_url', null);
        $this->assertStringContainsString('drivers/a.jpg', $response->json('data.0.image_url'));
    }

    public function test_invalid_or_missing_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'flying']))->assertStatus(422);
        $this->actingAs($user)->getJson(route('api.tour.drivers'))->assertStatus(422);
    }

    public function test_no_matching_driver_returns_empty_data(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['walking'])->create();

        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'trucking']))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
