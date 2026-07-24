<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\Support\CodeGraphIntOrNull;
use PHPUnit\Framework\TestCase;

final class CodeGraphIntOrNullTest extends TestCase
{
    public function test_coerces_numeric_shapes(): void
    {
        $this->assertSame(3, CodeGraphIntOrNull::coerce(3));
        $this->assertSame(4, CodeGraphIntOrNull::coerce(4.9));
        $this->assertSame(7, CodeGraphIntOrNull::coerce('7'));
        $this->assertNull(CodeGraphIntOrNull::coerce(NAN));
        $this->assertNull(CodeGraphIntOrNull::coerce('nope'));
        $this->assertNull(CodeGraphIntOrNull::coerce(null));
    }
}
