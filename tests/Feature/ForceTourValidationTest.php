<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForceTourValidationTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [...['stops' => $this->stops(), 'travel_duration_s' => 5400], ...$overrides];
    }

    public function test_a_missing_duration_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), ['stops' => $this->stops()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('travel_duration_s');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDurations(): array
    {
        return [
            'zero' => [0],
            'negative' => [-60],
            'non-integer' => [12.5],
            'over 24 hours' => [86401],
            'non-numeric' => ['soon'],
        ];
    }

    #[DataProvider('invalidDurations')]
    public function test_it_rejects_an_invalid_duration(mixed $duration): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload(['travel_duration_s' => $duration]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('travel_duration_s');
    }

    public function test_it_accepts_the_maximum_duration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload(['travel_duration_s' => 86400]))
            ->assertOk();
    }

    public function test_it_requires_at_least_two_stops(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload(['stops' => [['lat' => 49.9, 'lng' => 2.3, 'duration_s' => 100]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stops');
    }

    public function test_it_rejects_more_than_ten_stops(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload([
                'stops' => array_fill(0, 11, ['lat' => 48.0, 'lng' => 2.0, 'duration_s' => 600]),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stops');
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload([
                'stops' => [
                    ['lat' => 200, 'lng' => 2.30028, 'duration_s' => 600],
                    ['lat' => 48.45101, 'lng' => 6.74833, 'duration_s' => 600],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stops.0.lat');
    }

    public function test_it_rejects_an_unsupported_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.force'), $this->payload(['mode' => 'flying']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }
}
