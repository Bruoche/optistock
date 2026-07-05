<?php

namespace Tests\Unit;

use App\Models\Tour;
use App\Models\User;
use App\Services\TourRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TourRecorderEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{lat: float, lng: float, order: int}>  $orderedStops
     * @param  array<int, int>  $durations  parallel to $orderedStops
     * @return array<string, list<int>>
     */
    private function durationByCoord(array $orderedStops, array $durations): array
    {
        $map = [];
        foreach ($orderedStops as $index => $stop) {
            $map[TourRecorder::coordinateKey($stop['lat'], $stop['lng'])][] = $durations[$index];
        }

        return $map;
    }

    public function test_editing_updates_the_same_tour_and_replaces_its_stops(): void
    {
        $user = User::factory()->create();
        $existing = Tour::factory()->for($user)->withMode('trucking')->withStops(4)->create(['loop' => true]);

        $ordered = [
            ['lat' => 48.10, 'lng' => 2.10, 'order' => 0],
            ['lat' => 48.20, 'lng' => 2.20, 'order' => 1],
        ];

        $tour = app(TourRecorder::class)->record(
            $user->id,
            'walking',
            false,
            $ordered,
            $this->durationByCoord($ordered, [120, 240]),
            5000,
            360,
            $existing->id,
        );

        $this->assertSame($existing->id, $tour->id);
        $this->assertDatabaseCount('tours', 1);

        $tour->refresh()->load(['stops', 'deliveryMode']);
        $this->assertSame('walking', $tour->deliveryMode->label);
        $this->assertFalse($tour->loop);
        $this->assertSame(360, $tour->travel_duration_s);
        $this->assertSame([120, 240], $tour->stops->pluck('duration_s')->all());
        $this->assertSame([0, 1], $tour->stops->pluck('position')->all());
    }

    public function test_a_missing_edit_target_throws_and_creates_no_tour(): void
    {
        $user = User::factory()->create();
        $ordered = [['lat' => 48.10, 'lng' => 2.10, 'order' => 0]];

        try {
            app(TourRecorder::class)->record(
                $user->id,
                'trucking',
                true,
                $ordered,
                $this->durationByCoord($ordered, [60]),
                1,
                1,
                999999,
            );
            $this->fail('Expected a RuntimeException for a missing edit target.');
        } catch (RuntimeException) {
            // expected — the transaction rolls back, leaving no tour behind.
        }

        $this->assertDatabaseCount('tours', 0);
    }

    public function test_a_foreign_tour_id_is_not_editable_and_throws(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Tour::factory()->for($other)->withMode('trucking')->withStops(2)->create();

        $ordered = [['lat' => 48.10, 'lng' => 2.10, 'order' => 0]];

        $this->expectException(RuntimeException::class);
        app(TourRecorder::class)->record(
            $owner->id,
            'trucking',
            true,
            $ordered,
            $this->durationByCoord($ordered, [60]),
            1,
            1,
            $foreign->id,
        );
    }
}
