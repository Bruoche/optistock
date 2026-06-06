<?php

namespace Tests\Unit;

use App\Services\CoordinateNormalizer;
use PHPUnit\Framework\TestCase;

class CoordinateNormalizerTest extends TestCase
{
    private CoordinateNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CoordinateNormalizer;
    }

    public function test_it_rounds_coordinates_to_five_decimals(): void
    {
        $normalized = $this->normalizer->normalize([
            [49.899875712, 2.300284399],
            [48.451010444, 6.748332777],
        ]);

        $this->assertSame(['lat' => 48.45101, 'lng' => 6.74833], $normalized[0]);
        $this->assertSame(['lat' => 49.89988, 'lng' => 2.30028], $normalized[1]);
    }

    public function test_same_coordinate_set_in_any_order_normalizes_identically(): void
    {
        $a = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]]);
        $b = $this->normalizer->normalize([[5.0, 6.0], [1.0, 2.0], [3.0, 4.0]]);

        $this->assertSame($a, $b);
    }

    public function test_different_coordinate_sets_normalize_differently(): void
    {
        $a = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.0]]);
        $b = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.1]]);

        $this->assertNotSame($a, $b);
    }
}
