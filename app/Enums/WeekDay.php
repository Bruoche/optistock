<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * The days of the week a driver may be scheduled to work — the single source of
 * the allowed set, mirrored by the week_days lookup table. Backing values are
 * lowercase English day names (matching the seeded labels).
 */
enum WeekDay: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    /**
     * The weekday a date falls on. Uses ISO day-of-week (1 = Monday … 7 = Sunday),
     * which is independent of locale and timezone formatting.
     */
    public static function fromDate(CarbonInterface $date): self
    {
        return match ($date->dayOfWeekIso) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            7 => self::Sunday,
        };
    }
}
