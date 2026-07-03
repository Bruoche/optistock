<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Assembles the drawable legs of a driver's projected workday, in chain order:
 * connection into each prior tour, the tour itself, …, the connection into the
 * candidate tour and the return to the warehouse. The candidate tour is not a
 * leg — the client already draws it in the highlight color.
 */
class WorkdayLegsBuilder
{
    public function __construct(private readonly TravelTimeService $travelTime) {}

    /**
     * @param  array<int, PriorTourLeg>  $priorTours
     * @return array<int, WorkdayLeg>
     */
    public function build(
        Coordinate $warehouse,
        array $priorTours,
        Coordinate $candidateStart,
        Coordinate $candidateEnd,
        ?string $mode = null,
    ): array {
        $legs = [];
        $previous = $warehouse;
        foreach ($priorTours as $priorTour) {
            $legs[] = $this->connection($previous, $priorTour->start, $mode);
            $legs[] = WorkdayLeg::tour($this->tourPath($priorTour), $priorTour->loop);
            $previous = $priorTour->end;
        }
        $legs[] = $this->connection($previous, $candidateStart, $mode);
        $legs[] = $this->connection($candidateEnd, $warehouse, $mode);

        return $legs;
    }

    private function connection(Coordinate $from, Coordinate $to, ?string $mode): WorkdayLeg
    {
        return WorkdayLeg::connection($from, $to, $this->travelTime->geometryBetween($from, $to, $mode));
    }

    /**
     * The tour's stop coordinates ordered as driven — rotated to begin at the
     * recorded start for a loop, reversed for a one-way entered at its far end.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function tourPath(PriorTourLeg $tour): array
    {
        return array_map(
            fn (Coordinate $stop): array => [$stop->lat, $stop->lng],
            $this->stopsInDrivenOrder($tour),
        );
    }

    /**
     * @return array<int, Coordinate>
     */
    private function stopsInDrivenOrder(PriorTourLeg $tour): array
    {
        $stops = $tour->stopCoordinates;

        if ($tour->loop) {
            foreach ($stops as $index => $stop) {
                if ($stop->isSameAs($tour->start)) {
                    return [...array_slice($stops, $index), ...array_slice($stops, 0, $index)];
                }
            }
        } elseif ($stops !== []) {
            if ($tour->start->isSameAs($stops[0])) {
                return $stops;
            }
            if ($tour->start->isSameAs($stops[array_key_last($stops)])) {
                return array_reverse($stops);
            }
        }

        Log::warning('Assigned tour start matches none of its stops; drawing in stored order', [
            'start' => $tour->start->toQueryValue(),
        ]);

        return $stops;
    }
}
