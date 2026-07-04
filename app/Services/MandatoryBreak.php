<?php

namespace App\Services;

/**
 * Legally mandated rest break for a driver's day, in seconds. The larger of two rules,
 * never their sum: 45 min per completed 4 h 30 of driving, and a workday break of 30 min
 * over 6 h or 45 min over 9 h of total working time.
 */
final class MandatoryBreak
{
    private const DRIVING_BLOCK_S = 16200; // 4 h 30

    private const DRIVING_BREAK_S = 2700;  // 45 min per block

    private const WORKDAY_6H_S = 21600;

    private const WORKDAY_9H_S = 32400;

    private const BREAK_30_S = 1800;

    private const BREAK_45_S = 2700;

    public static function secondsFor(int $workdayS, int $drivingS): int
    {
        $drivingBreak = intdiv($drivingS, self::DRIVING_BLOCK_S) * self::DRIVING_BREAK_S;

        $workdayBreak = match (true) {
            $workdayS > self::WORKDAY_9H_S => self::BREAK_45_S,
            $workdayS > self::WORKDAY_6H_S => self::BREAK_30_S,
            default => 0,
        };

        return max($drivingBreak, $workdayBreak);
    }
}
