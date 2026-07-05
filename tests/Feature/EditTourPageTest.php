<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EditTourPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function tourWithStops(): Tour
    {
        $tour = Tour::factory()->for($this->user)->withMode('walking')->create(['loop' => false]);
        Stop::factory()->for($tour)->create(['latitude' => 48.10, 'longitude' => 2.10, 'duration_s' => 900, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.20, 'longitude' => 2.20, 'duration_s' => 1200, 'position' => 1]);

        return $tour;
    }

    public function test_the_plain_tour_page_has_a_null_edit_tour(): void
    {
        $this->actingAs($this->user)
            ->get(route('tour.optimize.page'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tour/optimize')
                ->where('editTour', null),
            );
    }

    public function test_the_edit_page_hydrates_the_owned_unassigned_tour(): void
    {
        $tour = $this->tourWithStops();

        $this->actingAs($this->user)
            ->get(route('tour.edit.page', $tour))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tour/optimize')
                ->where('editTour.id', $tour->id)
                ->where('editTour.mode', 'walking')
                ->where('editTour.loop', false)
                ->has('editTour.stops', 2)
                ->where('editTour.stops.0.duration_minutes', 15)
                ->where('editTour.stops.1.duration_minutes', 20)
                ->etc(),
            );
    }

    public function test_a_foreign_tour_edit_page_returns_404(): void
    {
        $foreign = Tour::factory()->for(User::factory()->create())->withMode('walking')->withStops(2)->create();

        $this->actingAs($this->user)
            ->get(route('tour.edit.page', $foreign))
            ->assertNotFound();
    }

    public function test_an_assigned_tour_is_not_editable_and_redirects(): void
    {
        $driver = Driver::factory()->create();
        $assigned = Tour::factory()->for($this->user)->withMode('walking')->withStops(2)
            ->assignedTo($driver, '2026-07-06')->create();

        $this->actingAs($this->user)
            ->get(route('tour.edit.page', $assigned))
            ->assertRedirect(route('tour.optimize.page'));
    }
}
