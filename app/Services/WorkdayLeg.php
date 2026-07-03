<?php

namespace App\Services;

/**
 * One drawable piece of a driver's projected workday, in chain order: a connection
 * drive (dotted) or an already-assigned tour (solid). The candidate tour is never a
 * leg — the client draws it separately in the highlight color.
 */
final class WorkdayLeg
{
    public const KIND_CONNECTION = 'connection';

    public const KIND_TOUR = 'tour';

    /**
     * @param  array<int, array{0: float, 1: float}>  $path  straight-line fallback points
     * @param  array<int, array{0: float, 1: float}>|null  $geometry  decoded road coordinates, null when the client traces lazily
     * @param  bool  $loop  trace flag, true only for a looping tour leg
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $dotted,
        public readonly array $path,
        public readonly ?array $geometry,
        public readonly bool $loop,
    ) {}

    /**
     * @param  array<int, array{0: float, 1: float}>|null  $geometry
     */
    public static function connection(Coordinate $from, Coordinate $to, ?array $geometry): self
    {
        return new self(
            kind: self::KIND_CONNECTION,
            dotted: true,
            path: [[$from->lat, $from->lng], [$to->lat, $to->lng]],
            geometry: $geometry,
            loop: false,
        );
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $path
     */
    public static function tour(array $path, bool $loop): self
    {
        return new self(
            kind: self::KIND_TOUR,
            dotted: false,
            path: $path,
            geometry: null,
            loop: $loop,
        );
    }

    /**
     * @return array{
     *     kind: string,
     *     dotted: bool,
     *     path: array<int, array{0: float, 1: float}>,
     *     geometry: array<int, array{0: float, 1: float}>|null,
     *     loop: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'dotted' => $this->dotted,
            'path' => $this->path,
            'geometry' => $this->geometry,
            'loop' => $this->loop,
        ];
    }
}
