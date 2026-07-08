<?php

namespace App\Services;

use App\Models\Tour;
use App\Repositories\TourRepository;
use RuntimeException;

/**
 * Turns an optimized tour into persisted rows: it recovers each ordered stop's
 * delivery duration, then hands the tour + its stop rows to {@see TourRepository}
 * to save (create, or overwrite in place for an edit — feature 020).
 *
 * Per-stop delivery durations reach us keyed by normalized coordinate (built by
 * {@see TourOptimizationService}) — an input index cannot carry them because the
 * coordinate set is rounded + re-sorted before optimization and the cache-hit path
 * makes no upstream call. Each ordered stop's duration is recovered by coordinate.
 * A coordinate with no entry is a broken invariant: we throw before persisting
 * anything rather than silently saving a zero, so the failure is surfaced (FR-014).
 */
class TourRecorder
{
    public function __construct(private readonly TourRepository $tours) {}

    /**
     * The map key for a coordinate: the two components rounded to the normalizer's
     * precision so a request coordinate and its (already-normalized) ordered stop
     * resolve to the same key. Shares the single rounding source with {@see Coordinate}.
     */
    public static function coordinateKey(float $lat, float $lng): string
    {
        return Coordinate::keyFor($lat, $lng);
    }

    /**
     * @param  array<int, array{lat: float, lng: float, order: int}>  $orderedStops
     * @param  array<string, list<int>>  $durationByCoord  coordinate key → queue of durations (dup-coord safe)
     * @param  int|null  $editTourId  when set, overwrite this existing tour in place instead of creating one (feature 020)
     */
    public function record(
        int $userId,
        string $mode,
        bool $loop,
        array $orderedStops,
        array $durationByCoord,
        ?int $distanceM,
        ?int $durationS,
        ?int $editTourId = null,
    ): Tour {
        $stopRows = $this->buildStopRows($orderedStops, $durationByCoord);

        if ($editTourId === null) {
            return $this->tours->createTourWithStops($userId, $mode, $loop, $distanceM, $durationS, $stopRows);
        }

        return $this->tours->overwriteTourWithStops($editTourId, $userId, $mode, $loop, $distanceM, $durationS, $stopRows);
    }

    /**
     * Re-attach each ordered stop's delivery duration by coordinate, producing the
     * persistable rows in visiting order.
     *
     * @param  array<int, array{lat: float, lng: float, order: int}>  $orderedStops
     * @param  array<string, list<int>>  $durationByCoord
     * @return array<int, array{latitude: float, longitude: float, duration_s: int, position: int}>
     */
    private function buildStopRows(array $orderedStops, array $durationByCoord): array
    {
        $stopRows = [];
        // Kept as a loop (not array_map): durationFor pops the per-coordinate queue by
        // reference, so duplicate coordinates must share one mutating $durationByCoord.
        foreach ($orderedStops as $stop) {
            $stopRows[] = [
                'latitude' => $stop['lat'],
                'longitude' => $stop['lng'],
                'duration_s' => $this->durationFor($stop, $durationByCoord),
                'position' => $stop['order'],
            ];
        }

        return $stopRows;
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
