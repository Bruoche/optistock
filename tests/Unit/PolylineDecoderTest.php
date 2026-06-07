<?php

namespace Tests\Unit;

use App\Services\PolylineDecoder;
use PHPUnit\Framework\TestCase;

class PolylineDecoderTest extends TestCase
{
    public function test_it_decodes_googles_canonical_example(): void
    {
        $decoder = new PolylineDecoder;

        // Canonical vector from Google's polyline algorithm docs (precision 5).
        $points = $decoder->decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertSame([
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ], $points);
    }

    public function test_it_returns_empty_array_for_empty_string(): void
    {
        $this->assertSame([], (new PolylineDecoder)->decode(''));
    }

    public function test_it_decodes_a_single_point(): void
    {
        // Encoding of (38.5, -120.2).
        $points = (new PolylineDecoder)->decode('_p~iF~ps|U');

        $this->assertSame([[38.5, -120.2]], $points);
    }
}
