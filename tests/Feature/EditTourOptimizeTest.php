<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Tour;
use App\Models\User;
use App\Services\CoordinateNormalizer;
use App\Services\TourCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditTourOptimizeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function coordinates(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.45101, 'lng' => 6.74833],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ];
    }

    /**
     * Prime the cache so an optimize request resolves synchronously (cache hit) with a
     * known ordered-stop set — no HTTP/queue needed to exercise the persistence branch.
     */
    private function primeCache(): void
    {
        $normalized = app(CoordinateNormalizer::class)->normalize(
            array_map(fn (array $c): array => [$c['lat'], $c['lng']], $this->coordinates()),
        );
        $hash = hash('sha256', (string) json_encode($normalized));
        app(TourCache::class)->putTour('trucking', true, $hash, [
            'ordered_stops' => [
                ['lat' => 48.78300, 'lng' => 2.33316, 'order' => 0],
                ['lat' => 49.89988, 'lng' => 2.30028, 'order' => 1],
                ['lat' => 48.45101, 'lng' => 6.74833, 'order' => 2],
            ],
            'total_distance_m' => 4200,
            'total_duration_s' => 360,
        ]);
    }

    /**
     * @return array<int, array{lat: float, lng: float, duration_s: int}>
     */
    private function requestStops(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028, 'duration_s' => 100],
            ['lat' => 48.45101, 'lng' => 6.74833, 'duration_s' => 200],
            ['lat' => 48.78300, 'lng' => 2.33316, 'duration_s' => 300],
        ];
    }

    public function test_optimize_with_a_tour_id_updates_the_same_tour_in_place(): void
    {
        $existing = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)->create(['loop' => true]);
        $this->primeCache();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $this->requestStops(), 'tour_id' => $existing->id])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('data.id', $existing->id);

        // Same tour updated, not duplicated (SC-002).
        $this->assertDatabaseCount('tours', 1);
        $tour = Tour::with('stops')->find($existing->id);
        // ordered_stops order: coord 48.783 (dur 300), 49.899 (dur 100), 48.451 (dur 200).
        $this->assertSame([300, 100, 200], $tour->stops->pluck('duration_s')->all());
    }

    public function test_optimize_without_a_tour_id_creates_a_new_tour(): void
    {
        $existing = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)->create(['loop' => true]);
        $this->primeCache();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $this->requestStops()])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        // A second, distinct tour now exists.
        $this->assertDatabaseCount('tours', 2);
    }

    public function test_an_assigned_tour_cannot_be_edited(): void
    {
        $driver = Driver::factory()->create();
        $assigned = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)
            ->assignedTo($driver, '2026-07-06')->create(['loop' => true]);

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $this->requestStops(), 'tour_id' => $assigned->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tour_id');
    }

    public function test_a_driver_management_edit_may_reoptimize_an_assigned_tour(): void
    {
        // Feature 025: `return_to_driver` allows editing an assigned tour in place.
        $driver = Driver::factory()->create();
        $assigned = Tour::factory()->for($this->user)->withMode('trucking')->withStops(2)
            ->assignedTo($driver, '2026-07-06')->create(['loop' => true]);
        $this->primeCache();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), [
                'stops' => $this->requestStops(),
                'tour_id' => $assigned->id,
                'return_to_driver' => $driver->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('data.id', $assigned->id);
    }

    public function test_a_foreign_tour_id_returns_404(): void
    {
        $foreign = Tour::factory()->for(User::factory()->create())->withMode('trucking')->withStops(2)->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $this->requestStops(), 'tour_id' => $foreign->id])
            ->assertNotFound();
    }

    public function test_an_unknown_tour_id_returns_404(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $this->requestStops(), 'tour_id' => 999999])
            ->assertNotFound();
    }
}
