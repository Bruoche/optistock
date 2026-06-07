<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TourGeometryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function stops(): array
    {
        return [
            [49.89988, 2.30028],
            [48.45101, 6.74833],
            [48.78300, 2.33316],
        ];
    }

    private function okLeg(int $distance = 1000, int $duration = 120): array
    {
        return ['polyline' => '_p~iF~ps|U', 'total_distance' => $distance, 'total_time' => $duration, 'status' => 0];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(route('api.tour.geometry'), ['stops' => $this->stops()])
            ->assertUnauthorized();
    }

    public function test_it_validates_stops(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.tour.geometry'), ['stops' => [[49.89988, 2.30028]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stops');
    }

    public function test_it_rejects_an_invalid_mode(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.tour.geometry'), ['stops' => $this->stops(), 'mode' => 'teleport'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_it_traces_every_leg_and_compounds_totals(): void
    {
        Http::fake(['*' => Http::response($this->okLeg(1000, 120))]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('api.tour.geometry'), ['stops' => $this->stops()]);

        $response->assertOk();
        // Closed tour of 3 stops → 3 legs (incl. return), all ok.
        $response->assertJsonCount(3, 'legs');
        $response->assertJsonPath('legs.0.ok', true);
        $response->assertJsonPath('total_distance_m', 3000);
        $response->assertJsonPath('total_duration_s', 360);
        // Decoded coordinates present (exact values are precision-specific — covered in the unit test).
        $this->assertNotEmpty($response->json('legs.0.coordinates'));
    }

    public function test_a_failed_leg_falls_back_and_nulls_totals_and_is_logged(): void
    {
        Log::spy();
        // Legs 1 & 2 succeed, leg 3 (return) fails with an error status.
        Http::fake(['*' => Http::sequence()
            ->push($this->okLeg())
            ->push($this->okLeg())
            ->push(['status' => 'WRONG_KEY']),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('api.tour.geometry'), ['stops' => $this->stops()]);

        $response->assertOk();
        $response->assertJsonPath('legs.0.ok', true);
        $response->assertJsonPath('legs.2.ok', false);
        $response->assertJsonPath('total_distance_m', null);
        $response->assertJsonPath('total_duration_s', null);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_whole_tour_failure_keeps_all_legs_falling_back(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('api.tour.geometry'), ['stops' => $this->stops()]);

        $response->assertOk();
        $response->assertJsonPath('legs.0.ok', false);
        $response->assertJsonPath('total_distance_m', null);
    }
}
