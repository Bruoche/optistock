<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 025 (FR-045/046): a driver's day is single-mode. The available-drivers request
 * must exclude a driver already committed to a different mode on the requested date.
 */
class AvailableDriversModeTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-06';

    private const OTHER_MONDAY = '2026-07-13';

    protected function setUp(): void
    {
        parent::setUp();

        // Routing is irrelevant here — we assert who is listed, not their projected times.
        Http::fake(fn () => throw new ConnectionException('no routing in this test'));
    }

    private function walkingCandidate(User $user): Tour
    {
        $tour = Tour::factory()->withMode('walking')->create([
            'user_id' => $user->id,
            'loop' => true,
        ]);
        Stop::factory()->for($tour)->create(['latitude' => 48.85, 'longitude' => 2.35, 'duration_s' => 60, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.86, 'longitude' => 2.36, 'duration_s' => 60, 'position' => 1]);

        return $tour;
    }

    private function assignedTour(Driver $driver, string $mode, string $date): void
    {
        Tour::factory()
            ->withMode($mode)
            ->withStops(2)
            ->assignedTo($driver, $date)
            ->create(['loop' => true]);
    }

    public function test_a_driver_committed_to_another_mode_that_day_is_excluded(): void
    {
        $user = User::factory()->create();
        $candidate = $this->walkingCandidate($user);

        $committed = Driver::factory()->withModes(['walking', 'driving'])->withDays(['monday'])->create(['name' => 'Committed']);
        $free = Driver::factory()->withModes(['walking'])->withDays(['monday'])->create(['name' => 'Free']);
        $sameMode = Driver::factory()->withModes(['walking'])->withDays(['monday'])->create(['name' => 'SameMode']);
        $otherDay = Driver::factory()->withModes(['walking', 'driving'])->withDays(['monday'])->create(['name' => 'OtherDay']);

        $this->assignedTour($committed, 'driving', self::MONDAY);   // different mode, same day → excluded
        $this->assignedTour($sameMode, 'walking', self::MONDAY);    // same mode, same day → kept
        $this->assignedTour($otherDay, 'driving', self::OTHER_MONDAY); // different mode, different day → kept

        $names = $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'walking', 'date' => self::MONDAY, 'tour' => $candidate->id]))
            ->assertOk()
            ->json('data.*.name');

        $this->assertContains('Free', $names);
        $this->assertContains('SameMode', $names);
        $this->assertContains('OtherDay', $names);
        $this->assertNotContains('Committed', $names);
    }
}
