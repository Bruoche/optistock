<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Feature 025: the driver-management page renders with its props; unknown driver 404s. */
class DriverPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_manage_page_with_driver_date_and_warehouses(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create(['name' => 'North Depot']);
        $driver = Driver::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->actingAs($user)
            ->get(route('driver.manage.page', ['driver' => $driver->id, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('driver/manage')
                ->where('driverId', $driver->id)
                ->where('initialDate', '2026-07-06')
                ->has('warehouses')
                ->missing('modes'));
    }

    public function test_it_defaults_the_date_to_today_when_none_is_given(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->create();

        $this->actingAs($user)
            ->get(route('driver.manage.page', ['driver' => $driver->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('initialDate', now()->toDateString()));
    }

    public function test_an_unknown_driver_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('driver.manage.page', ['driver' => 999999]))
            ->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $driver = Driver::factory()->create();

        $this->get(route('driver.manage.page', ['driver' => $driver->id]))
            ->assertRedirect();
    }
}
