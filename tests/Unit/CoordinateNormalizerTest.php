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
        $result = $this->normalizer->normalize([
            [49.899875712, 2.300284399],
            [48.451010444, 6.748332777],
        ]);

        $this->assertSame(
            ['lat' => 48.45101, 'lng' => 6.74833],
            $result['coordinates'][0],
        );
        $this->assertSame(
            ['lat' => 49.89988, 'lng' => 2.30028],
            $result['coordinates'][1],
        );
    }

    public function test_it_returns_a_sha256_hash(): void
    {
        $result = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.0]]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['hash']);
    }

    public function test_same_coordinate_set_in_any_order_yields_the_same_hash(): void
    {
        $a = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]]);
        $b = $this->normalizer->normalize([[5.0, 6.0], [1.0, 2.0], [3.0, 4.0]]);

        $this->assertSame($a['hash'], $b['hash']);
    }

    public function test_different_coordinate_sets_yield_different_hashes(): void
    {
        $a = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.0]]);
        $b = $this->normalizer->normalize([[1.0, 2.0], [3.0, 4.1]]);

        $this->assertNotSame($a['hash'], $b['hash']);
    }
}
