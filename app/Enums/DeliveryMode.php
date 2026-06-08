<?php

namespace App\Enums;

/**
 * The travel mode for a delivery tour — the single source of the allowed set,
 * shared by the optimization request (001) and the geometry request (002) and
 * used as the default when a request omits the mode.
 *
 * The backing values are the exact strings the OpenStreet TSP and /route
 * endpoints expect for their `mode` query parameter (verified live in 002).
 */
enum DeliveryMode: string
{
    case Trucking = 'trucking';
    case Driving = 'driving';
    case Walking = 'walking';

    /** The mode assumed when a request does not specify one (delivery routes). */
    public static function default(): self
    {
        return self::Trucking;
    }
}
