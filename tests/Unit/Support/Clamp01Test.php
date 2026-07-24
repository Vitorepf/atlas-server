<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Clamp01;
use PHPUnit\Framework\TestCase;

final class Clamp01Test extends TestCase
{
    public function test_clamps_float_range(): void
    {
        $this->assertSame(0.0, Clamp01::of(-1.5));
        $this->assertSame(1.0, Clamp01::of(2.0));
        $this->assertSame(0.25, Clamp01::of(0.25));
    }

    public function test_from_mixed(): void
    {
        $this->assertSame(0.5, Clamp01::fromMixed('0.5'));
        $this->assertSame(0.0, Clamp01::fromMixed('nope'));
        $this->assertSame(1.0, Clamp01::fromMixed(9));
    }
}
