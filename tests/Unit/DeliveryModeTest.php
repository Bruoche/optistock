<?php

namespace Tests\Unit;

use App\Enums\DeliveryMode;
use PHPUnit\Framework\TestCase;

class DeliveryModeTest extends TestCase
{
    public function test_exposes_exactly_the_three_supported_modes(): void
    {
        $values = array_map(static fn (DeliveryMode $mode): string => $mode->value, DeliveryMode::cases());

        $this->assertSame(['trucking', 'driving', 'walking'], $values);
    }

    public function test_backing_values_match_the_open_street_query_strings(): void
    {
        $this->assertSame('trucking', DeliveryMode::Trucking->value);
        $this->assertSame('driving', DeliveryMode::Driving->value);
        $this->assertSame('walking', DeliveryMode::Walking->value);
    }

    public function test_default_is_trucking(): void
    {
        $this->assertSame(DeliveryMode::Trucking, DeliveryMode::default());
    }
}
