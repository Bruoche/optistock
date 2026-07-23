<?php

namespace Tests\Feature;

use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Feature 025 (US5): the tour-edit page carries a return target when opened from a driver page. */
class TourEditReturnTest extends TestCase
{
    use RefreshDatabase;

    private function editableTour(User $user): Tour
    {
        $tour = Tour::factory()->withMode('driving')->create(['user_id' => $user->id, 'loop' => true]);
        Stop::factory()->for($tour)->create(['position' => 0]);
        Stop::factory()->for($tour)->create(['position' => 1]);

        return $tour;
    }

    public function test_it_threads_a_return_target_into_the_edit_prop(): void
    {
        $user = User::factory()->create();
        $tour = $this->editableTour($user);

        $this->actingAs($user)
            ->get(route('tour.edit.page', ['tour' => $tour->id]).'?return_to_driver=7&return_to_date=2026-07-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tour/optimize')
                ->where('editTour.returnTo.driverId', 7)
                ->where('editTour.returnTo.date', '2026-07-06'));
    }

    public function test_a_normal_edit_has_no_return_target(): void
    {
        $user = User::factory()->create();
        $tour = $this->editableTour($user);

        $this->actingAs($user)
            ->get(route('tour.edit.page', ['tour' => $tour->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tour/optimize')
                ->missing('editTour.returnTo'));
    }
}
