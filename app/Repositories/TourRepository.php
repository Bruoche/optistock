<?php

namespace App\Repositories;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Tour;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every `Tour`/`Stop` database operation. A tour and its stops are always
 * written together in one transaction, so a mid-write failure leaves nothing
 * behind (feature 020: an edit that cannot find its target throws and rolls back
 * rather than duplicating the tour).
 *
 * @phpstan-type StopRow array{latitude: float, longitude: float, duration_s: int, position: int}
 */
class TourRepository
{
    /**
     * @param  array<int, StopRow>  $stopRows  the optimized stops, in visiting order
     */
    public function createTourWithStops(
        int $userId,
        string $mode,
        bool $loop,
        ?int $distanceM,
        ?int $durationS,
        array $stopRows,
    ): Tour {
        return DB::transaction(function () use ($userId, $mode, $loop, $distanceM, $durationS, $stopRows): Tour {
            $tour = Tour::create([
                'user_id' => $userId,
                'delivery_mode_id' => $this->deliveryModeId($mode),
                'loop' => $loop,
                'travel_duration_s' => $durationS,
                'total_distance_m' => $distanceM,
            ]);
            $this->attachStops($tour, $stopRows);

            return $tour;
        });
    }

    /**
     * Update the owned tour in place and replace its stops. A target that is gone or
     * no longer the user's is a broken invariant — throw to roll back, surfacing as
     * `persist_failed` rather than silently creating a duplicate.
     *
     * @param  array<int, StopRow>  $stopRows
     */
    public function overwriteTourWithStops(
        int $tourId,
        int $userId,
        string $mode,
        bool $loop,
        ?int $distanceM,
        ?int $durationS,
        array $stopRows,
    ): Tour {
        return DB::transaction(function () use ($tourId, $userId, $mode, $loop, $distanceM, $durationS, $stopRows): Tour {
            $tour = $this->findOwnedTour($tourId, $userId)
                ?? throw new RuntimeException("Edit target tour {$tourId} not found for user {$userId}.");

            $tour->update([
                'delivery_mode_id' => $this->deliveryModeId($mode),
                'loop' => $loop,
                'travel_duration_s' => $durationS,
                'total_distance_m' => $distanceM,
            ]);
            $tour->stops()->delete();
            $this->attachStops($tour, $stopRows);

            return $tour;
        });
    }

    public function findOwnedTour(int $tourId, int $userId): ?Tour
    {
        return Tour::where('user_id', $userId)->find($tourId);
    }

    /** Overwrite a tour's road totals with the road-accurate traced values (feature 002). */
    public function updateRoadTotals(Tour $tour, int $distanceM, int $durationS): void
    {
        $tour->update([
            'travel_duration_s' => $durationS,
            'total_distance_m' => $distanceM,
        ]);
    }

    private function deliveryModeId(string $mode): int
    {
        return DeliveryMode::firstOrCreate(['label' => DeliveryModeEnum::from($mode)->value])->id;
    }

    /**
     * @param  array<int, StopRow>  $stopRows
     */
    private function attachStops(Tour $tour, array $stopRows): void
    {
        foreach ($stopRows as $stopRow) {
            $tour->stops()->create($stopRow);
        }
    }
}
