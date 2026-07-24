<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RoundOrNull;
use PHPUnit\Framework\TestCase;

final class RoundOrNullTest extends TestCase
{
    public function test_null_and_round(): void
    {
        $this->assertNull(RoundOrNull::of(null));
        $this->assertSame(1.235, RoundOrNull::of(1.23456));
    }
}
