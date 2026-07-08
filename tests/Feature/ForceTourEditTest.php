<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForceTourEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array{lat: float, lng: float, duration_s: int}>
     */
    private function stops(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028, 'duration_s' => 100],
            ['lat' => 48.45101, 'lng' => 6.74833, 'duration_s' => 200],
        ];
    }

    public function test_forcing_with_a_tour_id_updates_the_same_tour_in_place(): void
    {
        $existing = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)->create(['loop' => true]);

        $this->actingAs($this->user)
            ->postJson(route('api.tour.force'), [
                'stops' => $this->stops(),
                'travel_duration_s' => 5400,
                'tour_id' => $existing->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('data.id', $existing->id);

        // Same tour overwritten, not duplicated; stops replaced in input order.
        $this->assertDatabaseCount('tours', 1);
        $tour = Tour::with('stops')->find($existing->id);
        $this->assertSame(5400, $tour->travel_duration_s);
        $this->assertSame([100, 200], $tour->stops->pluck('duration_s')->all());
    }

    public function test_forcing_without_a_tour_id_creates_a_new_tour(): void
    {
        Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.force'), ['stops' => $this->stops(), 'travel_duration_s' => 1200])
            ->assertOk();

        $this->assertDatabaseCount('tours', 2);
    }

    public function test_a_foreign_tour_id_returns_404(): void
    {
        $foreign = Tour::factory()->for(User::factory()->create())->withMode('trucking')->withStops(2)->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.force'), [
                'stops' => $this->stops(),
                'travel_duration_s' => 1200,
                'tour_id' => $foreign->id,
            ])
            ->assertNotFound();
    }

    public function test_an_unknown_tour_id_returns_404(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.tour.force'), [
                'stops' => $this->stops(),
                'travel_duration_s' => 1200,
                'tour_id' => 999999,
            ])
            ->assertNotFound();
    }

    public function test_an_assigned_tour_cannot_be_forced(): void
    {
        $driver = Driver::factory()->create();
        $assigned = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)
            ->assignedTo($driver, '2026-07-06')->create(['loop' => true]);

        $this->actingAs($this->user)
            ->postJson(route('api.tour.force'), [
                'stops' => $this->stops(),
                'travel_duration_s' => 1200,
                'tour_id' => $assigned->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tour_id');
    }
}
