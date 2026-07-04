<?php

namespace Tests\Unit;

use App\Services\MandatoryBreak;
use PHPUnit\Framework\TestCase;

class MandatoryBreakTest extends TestCase
{
    public function test_workday_break_thresholds_are_strict(): void
    {
        $this->assertSame(0, MandatoryBreak::secondsFor(21600, 0));    // exactly 6 h → none
        $this->assertSame(1800, MandatoryBreak::secondsFor(21601, 0)); // just over 6 h → 30 min
        $this->assertSame(1800, MandatoryBreak::secondsFor(32400, 0)); // exactly 9 h → still 30 min
        $this->assertSame(2700, MandatoryBreak::secondsFor(32401, 0)); // just over 9 h → 45 min
    }

    public function test_driving_break_counts_completed_blocks(): void
    {
        $this->assertSame(0, MandatoryBreak::secondsFor(0, 16199));    // under one 4 h 30 block
        $this->assertSame(2700, MandatoryBreak::secondsFor(0, 16200)); // one block → 45 min
        $this->assertSame(5400, MandatoryBreak::secondsFor(0, 32400)); // two blocks → 90 min
    }

    public function test_it_takes_the_larger_of_the_two_never_the_sum(): void
    {
        // Workday >9h → 45 min; driving = 32400 → 90 min. Max is 90, not 135.
        $this->assertSame(5400, MandatoryBreak::secondsFor(32401, 32400));
        // Workday break dominates when driving is small.
        $this->assertSame(1800, MandatoryBreak::secondsFor(25200, 3600));
    }

    public function test_a_short_day_needs_no_break(): void
    {
        $this->assertSame(0, MandatoryBreak::secondsFor(21600, 16199));
    }

    public function test_a_walked_day_gets_the_workday_break_but_not_the_driving_break(): void
    {
        // Driving rule off: 32400 s of driving contributes nothing; only the workday rule applies.
        $this->assertSame(0, MandatoryBreak::secondsFor(21600, 32400, drivingRuleApplies: false));
        $this->assertSame(1800, MandatoryBreak::secondsFor(21601, 32400, drivingRuleApplies: false));
        $this->assertSame(2700, MandatoryBreak::secondsFor(32401, 32400, drivingRuleApplies: false));
    }
}
