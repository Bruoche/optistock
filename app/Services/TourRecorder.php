<?php

namespace App\Services;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Stop;
use App\Models\Tour;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists an optimized tour and its ordered stops in one transaction.
 *
 * Per-stop delivery durations reach us keyed by normalized coordinate (built by
 * {@see TourOptimizationService}) — an input index cannot carry them because the
 * coordinate set is rounded + re-sorted before optimization and the cache-hit
 * path makes no upstream call. Each ordered stop's duration is recovered by
 * looking its coordinate up in that map. A coordinate with no entry is a broken
 * invariant: we throw (rolling the transaction back) rather than silently
 * persisting a zero, so the failure is surfaced to the user (FR-014).
 */
class TourRecorder
{
    /** Decimals kept when keying a coordinate — matches {@see CoordinateNormalizer}. */
    private const KEY_PRECISION = CoordinateNormalizer::PRECISION;

    /**
     * The map key for a coordinate: the two components rounded to the normalizer's
     * precision so a request coordinate and its (already-normalized) ordered stop
     * resolve to the same key.
     */
    public static function coordinateKey(float $lat, float $lng): string
    {
        return sprintf('%.'.self::KEY_PRECISION.'f,%.'.self::KEY_PRECISION.'f', $lat, $lng);
    }

    /**
     * @param  array<int, array{lat: float, lng: float, order: int}>  $orderedStops
     * @param  array<string, list<int>>  $durationByCoord  coordinate key → queue of durations (dup-coord safe)
     */
    public function record(
        int $userId,
        string $mode,
        bool $loop,
        array $orderedStops,
        array $durationByCoord,
        ?int $distanceM,
        ?int $durationS,
    ): Tour {
        $deliveryModeId = DeliveryMode::firstOrCreate(
            ['label' => DeliveryModeEnum::from($mode)->value],
        )->id;

        return DB::transaction(function () use (
            $userId,
            $deliveryModeId,
            $loop,
            $orderedStops,
            $durationByCoord,
            $distanceM,
            $durationS,
        ): Tour {
            $tour = Tour::create([
                'user_id' => $userId,
                'delivery_mode_id' => $deliveryModeId,
                'loop' => $loop,
                'travel_duration_s' => $durationS,
                'total_distance_m' => $distanceM,
            ]);

            foreach ($orderedStops as $stop) {
                $tour->stops()->create([
                    'latitude' => $stop['lat'],
                    'longitude' => $stop['lng'],
                    'duration_s' => $this->durationFor($stop, $durationByCoord),
                    'position' => $stop['order'],
                ]);
            }

            return $tour;
        });
    }

    /**
     * Pull the next duration queued for a stop's coordinate (order-preserving for
     * duplicate coordinates). No entry at all is an invariant break → throw.
     *
     * @param  array{lat: float, lng: float, order: int}  $stop
     * @param  array<string, list<int>>  $durationByCoord
     */
    private function durationFor(array $stop, array &$durationByCoord): int
    {
        $key = self::coordinateKey($stop['lat'], $stop['lng']);

        if (empty($durationByCoord[$key])) {
            throw new RuntimeException("No stop duration mapped for coordinate {$key}.");
        }

        return (int) array_shift($durationByCoord[$key]);
    }
}
