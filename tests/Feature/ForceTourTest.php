<?php

namespace Tests\Feature;

use App\Models\Tour;
use App\Models\User;
use App\Services\TourRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ForceTourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stops in a deliberate order with distinct per-stop durations, so a preserved
     * (unreordered) save is provable from the duration sequence.
     *
     * @return array<int, array{lat: float, lng: float, duration_s: int}>
     */
    private function stops(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028, 'duration_s' => 100],
            ['lat' => 48.45101, 'lng' => 6.74833, 'duration_s' => 200],
            ['lat' => 48.78300, 'lng' => 2.33316, 'duration_s' => 300],
        ];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(route('api.tour.force'), ['stops' => $this->stops(), 'travel_duration_s' => 5400])
            ->assertUnauthorized();
    }

    public function test_it_hard_writes_a_tour_in_input_order_with_the_manual_drive_duration(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('api.tour.force'), ['stops' => $this->stops(), 'travel_duration_s' => 5400]);

        $response->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('data.total_distance_m', null)
            // Driving-only figure = exactly the manual value, NOT drive + stop seconds.
            ->assertJsonPath('data.total_duration_s', 5400)
            ->assertJsonPath('data.id', fn ($id): bool => is_int($id));

        $this->assertDatabaseCount('tours', 1);
        $tour = Tour::with('stops')->firstOrFail();

        $this->assertSame(5400, $tour->travel_duration_s);
        $this->assertNull($tour->total_distance_m);
        // Stops kept in input order: positions + duration sequence unchanged.
        $this->assertSame([0, 1, 2], $tour->stops->pluck('position')->all());
        $this->assertSame([100, 200, 300], $tour->stops->pluck('duration_s')->all());
        // The response total is the drive figure alone, distinct from drive + stops (5400 + 600).
        $this->assertSame(6000, $tour->total_duration_s);
        $this->assertNotSame($tour->total_duration_s, $response->json('data.total_duration_s'));
    }

    public function test_the_default_mode_and_loop_are_applied_when_omitted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), ['stops' => $this->stops(), 'travel_duration_s' => 1200])
            ->assertOk();

        $tour = Tour::with('deliveryMode')->firstOrFail();
        $this->assertTrue($tour->loop);
        $this->assertSame('trucking', $tour->deliveryMode->label);
    }

    public function test_it_respects_the_selected_mode_and_loop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), [
                'stops' => $this->stops(),
                'travel_duration_s' => 1200,
                'mode' => 'walking',
                'loop' => false,
            ])
            ->assertOk();

        $tour = Tour::with('deliveryMode')->firstOrFail();
        $this->assertFalse($tour->loop);
        $this->assertSame('walking', $tour->deliveryMode->label);
    }

    public function test_a_persistence_failure_is_logged_and_surfaced_as_persist_failed(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $this->mock(TourRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('write blew up'));
        });

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), ['stops' => $this->stops(), 'travel_duration_s' => 5400])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error.code', 'persist_failed');

        $this->assertDatabaseCount('tours', 0);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Tour persistence failed (forced)'
                && $context['user_id'] === $user->id
                && str_contains($context['error'], 'write blew up'))
            ->once();
    }
}
