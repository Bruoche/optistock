<?php

namespace App\Services;

/**
 * Assembles the drawable legs of a driver's already-planned day (feature 025), in chain
 * order: warehouse → tour1 → tour2 → … → warehouse. Every tour is a solid leg and every
 * connecting drive a dotted one, all in the neutral role — there is no candidate tour, so
 * nothing is highlighted server-side (the client emphasises the selected tour by index).
 *
 * Adapted from {@see WorkdayLegsBuilder}, which is candidate-centric (it appends two
 * highlighted brackets for a projected tour) and cannot express an all-neutral day.
 */
class DayLegsBuilder
{
    public function __construct(private readonly TravelTimeService $travelTime) {}

    /**
     * @param  array<int, DayAssignment>  $assignments  in running order
     * @return array<int, WorkdayLeg>
     */
    public function build(Coordinate $warehouse, array $assignments, ?string $mode = null): array
    {
        $legs = [];
        $previous = $warehouse;
        foreach ($assignments as $assignment) {
            $legs[] = $this->connection($previous, $assignment->start, $mode);
            $legs[] = WorkdayLeg::tour($this->tourPath($assignment), $assignment->loop);
            $previous = $assignment->end;
        }

        if ($assignments !== []) {
            $legs[] = $this->connection($previous, $warehouse, $mode);
        }

        return $legs;
    }

    private function connection(Coordinate $from, Coordinate $to, ?string $mode): WorkdayLeg
    {
        return WorkdayLeg::connection($from, $to, $this->travelTime->geometryBetween($from, $to, $mode));
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function tourPath(DayAssignment $assignment): array
    {
        return array_map(
            fn (Coordinate $stop): array => [$stop->lat, $stop->lng],
            $assignment->stopCoordinates(),
        );
    }
}
