<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FirstNonEmptyString;
use PHPUnit\Framework\TestCase;

final class FirstNonEmptyStringTest extends TestCase
{
    public function test_skips_empty_and_non_scalars(): void
    {
        $this->assertSame('ok', FirstNonEmptyString::from(['', '  ', [], null, 'ok', 'later']));
        $this->assertSame('', FirstNonEmptyString::from(['', null, []]));
    }
}
