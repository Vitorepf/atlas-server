<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NonEmptyStringOrFallback;
use PHPUnit\Framework\TestCase;

final class NonEmptyStringOrFallbackTest extends TestCase
{
    public function test_fallback_when_blank(): void
    {
        $this->assertSame('fb', NonEmptyStringOrFallback::of('  ', 'fb'));
        $this->assertSame('x', NonEmptyStringOrFallback::of(' x ', 'fb'));
    }
}
