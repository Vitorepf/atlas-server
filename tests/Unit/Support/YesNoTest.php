<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\YesNo;
use PHPUnit\Framework\TestCase;

final class YesNoTest extends TestCase
{
    public function test_format(): void
    {
        $this->assertSame('yes', YesNo::format(true));
        $this->assertSame('no', YesNo::format(false));
        $this->assertSame('yes', YesNo::format(1));
    }
}
