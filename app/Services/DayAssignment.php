<?php

namespace App\Services;

/**
 * One tour on a driver's day (feature 025): its running position, mode, recorded entry/exit,
 * and its stops in driven order (index 1..N) with their durations. The single source the
 * day payload, the drawable legs, and the workday total all read from.
 */
final class DayAssignment
{
    /**
     * @param  array<int, array{index: int, coordinate: Coordinate, duration_s: int}>  $stops  driven order
     */
    public function __construct(
        public readonly int $tourId,
        public readonly int $sequence,
        public readonly bool $loop,
        public readonly string $mode,
        public readonly Coordinate $start,
        public readonly Coordinate $end,
        public readonly array $stops,
        public readonly ?int $drivenDurationS,
        public readonly int $stopSecondsS,
    ) {}

    /** Whole-tour duration = road travel + stop time; null travel propagates as unknown (never 0). */
    public function totalDurationS(): ?int
    {
        return $this->drivenDurationS === null ? null : $this->drivenDurationS + $this->stopSecondsS;
    }

    /** The tour reduced to a workday segment (its own duration is travel + stops). */
    public function toSegment(): TourSegment
    {
        return new TourSegment($this->start, $this->end, $this->totalDurationS(), $this->stopSecondsS);
    }

    /**
     * The stop coordinates in driven order — the tour leg's drawable path.
     *
     * @return array<int, Coordinate>
     */
    public function stopCoordinates(): array
    {
        return array_map(fn (array $stop): Coordinate => $stop['coordinate'], $this->stops);
    }
}
