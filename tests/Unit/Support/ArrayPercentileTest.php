<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ArrayPercentile;
use PHPUnit\Framework\TestCase;

final class ArrayPercentileTest extends TestCase
{
    public function test_empty_and_single(): void
    {
        $this->assertNull(ArrayPercentile::ofSorted([], 0.5));
        $this->assertSame(3.0, ArrayPercentile::ofSorted([3], 0.9));
    }

    public function test_median_of_even_list(): void
    {
        $sorted = [1.0, 2.0, 3.0, 4.0];
        $this->assertSame(2.5, ArrayPercentile::ofSorted($sorted, 0.5));
    }
}
