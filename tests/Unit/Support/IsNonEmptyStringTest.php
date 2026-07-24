<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\IsNonEmptyString;
use PHPUnit\Framework\TestCase;

final class IsNonEmptyStringTest extends TestCase
{
    public function test_check(): void
    {
        $this->assertTrue(IsNonEmptyString::check(' a '));
        $this->assertFalse(IsNonEmptyString::check(''));
        $this->assertFalse(IsNonEmptyString::check(1));
    }
}
