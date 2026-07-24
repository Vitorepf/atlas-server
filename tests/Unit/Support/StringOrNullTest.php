<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StringOrNull;
use PHPUnit\Framework\TestCase;

final class StringOrNullTest extends TestCase
{
    public function test_trimmed(): void
    {
        $this->assertSame('x', StringOrNull::trimmed(' x '));
        $this->assertNull(StringOrNull::trimmed(''));
        $this->assertNull(StringOrNull::trimmed(1));
    }
}
