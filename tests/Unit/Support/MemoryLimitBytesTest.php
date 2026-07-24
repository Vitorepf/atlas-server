<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MemoryLimitBytes;
use PHPUnit\Framework\TestCase;

final class MemoryLimitBytesTest extends TestCase
{
    public function test_parses_common_php_memory_units(): void
    {
        $this->assertSame(0, MemoryLimitBytes::parse(''));
        $this->assertSame(512, MemoryLimitBytes::parse('512'));
        $this->assertSame(512 * 1024, MemoryLimitBytes::parse('512K'));
        $this->assertSame(256 * 1024 * 1024, MemoryLimitBytes::parse('256M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, MemoryLimitBytes::parse('2G'));
        $this->assertSame(128 * 1024 * 1024, MemoryLimitBytes::parse('128m'));
    }
}
