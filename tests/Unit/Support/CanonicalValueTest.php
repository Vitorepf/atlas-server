<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CanonicalValue;
use PHPUnit\Framework\TestCase;

final class CanonicalValueTest extends TestCase
{
    public function test_sorts_associative_keys_recursively(): void
    {
        $in = ['b' => 1, 'a' => ['y' => 2, 'x' => 3]];
        $out = CanonicalValue::canonicalize($in);
        $this->assertSame(['a', 'b'], array_keys($out));
        $this->assertSame(['x', 'y'], array_keys($out['a']));
    }

    public function test_preserves_list_order(): void
    {
        $in = [3, 1, 2];
        $this->assertSame([3, 1, 2], CanonicalValue::canonicalize($in));
    }
}
