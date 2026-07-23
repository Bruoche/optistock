<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Tour;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes a driver's day for a new running order (feature 025): it re-selects each
 * tour's entry/exit by chaining from the warehouse, re-measuring the connecting drives,
 * and returns the pivot rows to persist.
 *
 * A normal recompute needs every chain connection to be routable — it is BLOCKED (throws
 * {@see UnroutableConnectionException}) otherwise, since selecting an optimal entry needs
 * the times. A `force` recompute is routing-free: it takes each tour's lowest-position
 * entry so a degraded routing service never leaves the day un-reorderable (mirrors 024).
 */
class TourOrderService
{
    public function __construct(
        private readonly TravelTimeService $travelTime,
        private readonly TourStartSelector $startSelector,
    ) {}

    /**
     * @param  array<int, int>  $orderedTourIds  the day's tours in the desired order
     * @return array<int, array{tour_id: int, sequence: int, start_latitude: float, start_longitude: float, end_latitude: float, end_longitude: float}>
     */
    public function reorder(Driver $driver, array $orderedTourIds, bool $force): array
    {
        $tours = Tour::with(['stops', 'deliveryMode'])
            ->whereIn('id', $orderedTourIds)
            ->get()
            ->keyBy('id');

        $mode = $tours->get($orderedTourIds[0])?->deliveryMode->label;
        $warehouse = $driver->warehouse->coordinate;

        return $force
            ? $this->forcedRows($orderedTourIds, $tours)
            : $this->recomputedRows($orderedTourIds, $tours, $warehouse, $mode);
    }

    /**
     * Optimal recompute: chain from the warehouse, selecting each tour's nearest entry and
     * verifying the connection is routable. Blocks on the first unroutable connection.
     *
     * @param  array<int, int>  $orderedTourIds
     * @param  Collection<int, Tour>  $tours
     * @return array<int, array<string, mixed>>
     */
    private function recomputedRows(array $orderedTourIds, $tours, Coordinate $warehouse, ?string $mode): array
    {
        $rows = [];
        $incoming = $warehouse;

        foreach ($orderedTourIds as $sequence => $tourId) {
            $tour = $tours->get($tourId);
            $this->preloadEntryConnections($incoming, $tour, $mode);

            $start = $this->startSelector->select($incoming, $tour, $mode);
            $this->assertRoutable($incoming, $start->start, $mode);

            $rows[] = $this->row($tourId, $sequence, $start->start, $start->end);
            $incoming = $start->end;
        }

        $this->travelTime->preload([[$incoming, $warehouse]], $mode);
        $this->assertRoutable($incoming, $warehouse, $mode);

        return $rows;
    }

    /**
     * Routing-free force: each tour keeps its lowest-position entry (its exit deduced),
     * so the order can always be saved even while the routing service is degraded.
     *
     * @param  array<int, int>  $orderedTourIds
     * @param  Collection<int, Tour>  $tours
     * @return array<int, array<string, mixed>>
     */
    private function forcedRows(array $orderedTourIds, $tours): array
    {
        Log::warning('Tour order force-saved without routing recompute', [
            'tour_ids' => $orderedTourIds,
        ]);

        $rows = [];
        foreach ($orderedTourIds as $sequence => $tourId) {
            $tour = $tours->get($tourId);
            $startStop = $tour->startCandidates()->first();
            $endStop = $tour->endStopForStart($startStop);

            $rows[] = $this->row($tourId, $sequence, $startStop->coordinate, $endStop->coordinate);
        }

        return $rows;
    }

    private function preloadEntryConnections(Coordinate $incoming, Tour $tour, ?string $mode): void
    {
        $connections = $tour->startCandidates()
            ->map(fn ($stop): array => [$incoming, $stop->coordinate])
            ->all();

        $this->travelTime->preload($connections, $mode);
    }

    private function assertRoutable(Coordinate $from, Coordinate $to, ?string $mode): void
    {
        // The selector falls back to the first candidate on all-null durations, so it hides
        // routing failure — verify the chosen connection directly and block if it is unknown.
        if ($this->travelTime->durationBetween($from, $to, $mode) === null) {
            throw new UnroutableConnectionException($from, $to);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $tourId, int $sequence, Coordinate $start, Coordinate $end): array
    {
        return [
            'tour_id' => $tourId,
            'sequence' => $sequence,
            'start_latitude' => $start->lat,
            'start_longitude' => $start->lng,
            'end_latitude' => $end->lat,
            'end_longitude' => $end->lng,
        ];
    }
}
