<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StableJson;
use PHPUnit\Framework\TestCase;

final class StableJsonTest extends TestCase
{
    public function test_encode_soft_and_throw(): void
    {
        $this->assertSame('{"a":1}', StableJson::encodeSoft(['a' => 1]));
        $this->assertSame('{"a":1}', StableJson::encode(['a' => 1]));
    }
}
